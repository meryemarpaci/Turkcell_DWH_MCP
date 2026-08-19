<?php

declare(strict_types=1);

namespace App\Mcp;

/**
 * Lightweight MCP 2025-11 server (Streamable HTTP transport, stateless mode).
 *
 * Specification: https://modelcontextprotocol.io/specification/2025-11-05
 *
 * Endpoints exposed by api/mcp.php:
 *   POST /api/mcp  → JSON-RPC request/response (Content-Type: application/json)
 *                   OR SSE stream if Accept: text/event-stream
 *
 * Supported methods:
 *   initialize         → capabilities + server info
 *   tools/list         → all registered tools
 *   tools/call         → invoke a tool by name
 *   ping               → { jsonrpc, id, result: {} }
 */
final class McpServer
{
    public const PROTOCOL_VERSION = '2025-11-05';
    public const SERVER_NAME = 'dwh-analyst';
    public const SERVER_VERSION = '1.0.0';

    /** @var array<string, array{description:string,inputSchema:array<string,mixed>,handler:callable}> */
    private array $tools = [];

    public function register(string $name, string $description, array $inputSchema, callable $handler): void
    {
        $this->tools[$name] = [
            'description' => $description,
            'inputSchema' => $inputSchema,
            'handler' => $handler,
        ];
    }

    /** Handle one JSON-RPC 2.0 request and return the response array. */
    public function handleRequest(array $rpc): array
    {
        $id = $rpc['id'] ?? null;
        $method = (string) ($rpc['method'] ?? '');
        $params = is_array($rpc['params'] ?? null) ? $rpc['params'] : [];

        try {
            $result = match ($method) {
                'initialize' => $this->handleInitialize($params),
                'ping' => new \stdClass(),
                'tools/list' => $this->handleToolsList($params),
                'tools/call' => $this->handleToolsCall($params),
                default => throw new McpException(-32601, "Method not found: {$method}"),
            };
        } catch (McpException $e) {
            return $this->errorResponse($id, $e->getCode(), $e->getMessage(), $e->data);
        } catch (\Throwable $e) {
            return $this->errorResponse($id, -32603, 'Internal error: ' . $e->getMessage());
        }

        // Notifications (id === null) get no response
        if ($id === null) {
            return [];
        }

        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ];
    }

    /** Handle a batch of requests; return array of responses (empty entries dropped). */
    public function handleBatch(array $requests): array
    {
        $out = [];
        foreach ($requests as $req) {
            if (!is_array($req)) {
                continue;
            }
            $r = $this->handleRequest($req);
            if ($r !== []) {
                $out[] = $r;
            }
        }
        return $out;
    }

    // ── handlers ──────────────────────────────────────────────────────────────

    private function handleInitialize(array $params): array
    {
        return [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => [
                'tools' => ['listChanged' => false],
            ],
            'serverInfo' => [
                'name' => self::SERVER_NAME,
                'version' => self::SERVER_VERSION,
            ],
            'instructions' => 'DWH MCP: prefer analyze_kpi / analyze_breakdown / analyze_trend — '
                . 'these tools scan full filtered data and return compact summaries. '
                . 'execute_query is peek-only.',
        ];
    }

    private function handleToolsList(array $params): array
    {
        $tools = [];
        foreach ($this->tools as $name => $t) {
            $tools[] = [
                'name' => $name,
                'description' => $t['description'],
                'inputSchema' => $t['inputSchema'],
            ];
        }
        return ['tools' => $tools];
    }

    private function handleToolsCall(array $params): array
    {
        $name = (string) ($params['name'] ?? '');
        $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        if (!isset($this->tools[$name])) {
            throw new McpException(-32602, "Unknown tool: {$name}");
        }

        $handler = $this->tools[$name]['handler'];
        try {
            $result = $handler($args);
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Tool error: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }

        if (!is_array($result)) {
            $result = ['raw' => $result];
        }

        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]],
            'isError' => $result['ok'] === false ?? false,
        ];
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function errorResponse(mixed $id, int $code, string $msg, mixed $data = null): array
    {
        $err = ['code' => $code, 'message' => $msg];
        if ($data !== null) {
            $err['data'] = $data;
        }
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => $err,
        ];
    }
}
