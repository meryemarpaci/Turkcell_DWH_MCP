<?php

declare(strict_types=1);

/**
 * MCP Streamable HTTP endpoint — POST /api/mcp
 *
 * Implements MCP 2025-11-05 Streamable HTTP transport (stateless mode).
 */

require_once dirname(__DIR__) . '/php/bootstrap.php';

use App\Mcp\DwhToolRegistry;
use App\Mcp\McpServer;

// Analytics tools may scan full filtered sets.
$mcpTimeout = (int) (app_env('MCP_TIMEOUT_SECONDS', '300') ?? 300);
$mcpTimeout = max(60, $mcpTimeout);
@ini_set('max_execution_time', (string) $mcpTimeout);
@set_time_limit($mcpTimeout);
@ini_set('display_errors', '0');
error_reporting(E_ALL);

// Capture fatals as JSON-RPC errors (never leak HTML to MCP clients).
register_shutdown_function(static function (): void {
    $err = error_get_last();
    if ($err === null) {
        return;
    }
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($err['type'] ?? 0, $fatalTypes, true)) {
        return;
    }
    if (headers_sent()) {
        return;
    }
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'jsonrpc' => '2.0',
        'id' => null,
        'error' => [
            'code' => -32603,
            'message' => 'Internal error: ' . ($err['message'] ?? 'fatal'),
            'data' => [
                'file' => $err['file'] ?? null,
                'line' => $err['line'] ?? null,
            ],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
});

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['jsonrpc' => '2.0', 'error' => ['code' => -32600, 'message' => 'Use POST'], 'id' => null]);
    exit;
}

ob_start();

$mcpServer = new McpServer();
DwhToolRegistry::register($mcpServer);

$raw = (string) file_get_contents('php://input');
if (trim($raw) === '') {
    ob_end_clean();
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['jsonrpc' => '2.0', 'error' => ['code' => -32700, 'message' => 'Empty body'], 'id' => null]);
    exit;
}

$body = json_decode($raw, true);
if (!is_array($body)) {
    ob_end_clean();
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['jsonrpc' => '2.0', 'error' => ['code' => -32700, 'message' => 'Parse error'], 'id' => null]);
    exit;
}

$isBatch = array_is_list($body) && count($body) > 0 && is_array($body[0]);
$response = $isBatch
    ? $mcpServer->handleBatch($body)
    : $mcpServer->handleRequest($body);

// Drop accidental notices/warnings from buffer
ob_end_clean();

if ($response === []) {
    http_response_code(202);
    exit;
}

$json = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false) {
    $json = json_encode([
        'jsonrpc' => '2.0',
        'id' => $body['id'] ?? null,
        'error' => ['code' => -32603, 'message' => 'Failed to encode tool result'],
    ]);
}

$accept = strtolower(trim(explode(',', $_SERVER['HTTP_ACCEPT'] ?? '')[0]));
if ($accept === 'text/event-stream') {
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    echo "event: message\n";
    echo "data: {$json}\n\n";
    flush();
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo $json;
