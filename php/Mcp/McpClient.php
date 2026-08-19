<?php

declare(strict_types=1);

namespace App\Mcp;

/**
 * Lightweight MCP client — calls the local /api/mcp endpoint
 * using the Streamable HTTP transport (JSON-RPC 2.0 over POST).
 *
 * Usage:
 *   $client = new McpClient('http://localhost:8080/api/mcp');
 *   $result = $client->callTool('run_report', ['sql' => '...', 'report_type' => 'trend']);
 *   $tools  = $client->listTools();
 */
final class McpClient
{
    private string $endpoint;
    private int $timeoutSeconds;
    private int $nextId = 1;

    public function __construct(string $endpoint, int $timeoutSeconds = 30)
    {
        $this->endpoint = $endpoint;
        $this->timeoutSeconds = $timeoutSeconds;
    }

    // ── Public API ─────────────────────────────────────────────────────────────

    /**
     * Perform MCP initialize handshake (optional — stateless server ignores it,
     * but calling it allows capability negotiation in future versions).
     */
    public function initialize(): array
    {
        return $this->rpc('initialize', [
            'protocolVersion' => McpServer::PROTOCOL_VERSION,
            'capabilities' => [],
            'clientInfo' => ['name' => 'dwh-php-agent', 'version' => '1.0.0'],
        ]);
    }

    /** Return the list of tools the server exposes. */
    public function listTools(): array
    {
        $resp = $this->rpc('tools/list');
        return $resp['tools'] ?? [];
    }

    /**
     * Call a tool and return the decoded first text content block.
     * Throws \RuntimeException if the server signals an error.
     *
     * @param  array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    public function callTool(string $toolName, array $arguments = []): array
    {
        $resp = $this->rpc('tools/call', [
            'name' => $toolName,
            'arguments' => $arguments,
        ]);

        if (isset($resp['isError']) && $resp['isError'] === true) {
            $msg = $resp['content'][0]['text'] ?? 'Tool returned an error';
            throw new \RuntimeException("[MCP:{$toolName}] {$msg}");
        }

        $text = $resp['content'][0]['text'] ?? '';
        $decoded = json_decode($text, true);
        return is_array($decoded) ? $decoded : ['raw' => $text];
    }

    // ── Transport ──────────────────────────────────────────────────────────────

    /**
     * Send a single JSON-RPC 2.0 request and return result.
     * Throws \RuntimeException on transport or protocol error.
     *
     * @param  array<string,mixed>|null $params
     * @return array<string,mixed>
     */
    private function rpc(string $method, ?array $params = null): array
    {
        $id = $this->nextId++;
        $payload = ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method];
        if ($params !== null) {
            $payload['params'] = $params;
        }

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'Content-Length: ' . strlen($body),
                ]),
                'content' => $body,
                'timeout' => $this->timeoutSeconds,
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents($this->endpoint, false, $ctx);

        if ($raw === false) {
            throw new \RuntimeException("MCP transport error: could not reach {$this->endpoint}");
        }

        // Strip accidental PHP notices before first JSON brace
        $trim = ltrim($raw);
        if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[')) {
            $raw = $trim;
        } elseif (preg_match('/[\{\[]/', $raw, $m, PREG_OFFSET_CAPTURE)) {
            $raw = substr($raw, (int) $m[0][1]);
        }

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            $preview = mb_substr(trim(preg_replace('/\s+/', ' ', $raw) ?? $raw), 0, 180);
            throw new \RuntimeException(
                "MCP transport error: invalid JSON from server"
                . ($preview !== '' ? " (preview: {$preview})" : '')
            );
        }

        if (isset($json['error'])) {
            $e = $json['error'];
            throw new \RuntimeException(
                sprintf('[MCP error %d] %s', $e['code'] ?? 0, $e['message'] ?? 'unknown')
            );
        }

        return is_array($json['result'] ?? null) ? $json['result'] : [];
    }
}
