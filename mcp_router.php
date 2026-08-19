<?php

declare(strict_types=1);

/**
 * Standalone MCP server router.
 * Run on a separate port:
 *   php -S localhost:8081 mcp_router.php
 *
 * All requests are forwarded to api/mcp.php regardless of path,
 * so any MCP client can point to http://localhost:8081 or
 * http://localhost:8081/api/mcp — both work.
 */

require_once __DIR__ . '/php/bootstrap.php';

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');

// Health check
if ($uri === '/health') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'service' => 'dwh-mcp', 'port' => 8081]);
    return true;
}

// Route everything to the MCP endpoint
require __DIR__ . '/api/mcp.php';
return true;
