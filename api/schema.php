<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/php/bootstrap.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    $schema = (new App\Tools\SchemaTool())->listSchema();
    app_json_response($schema);
} catch (Throwable $e) {
    app_json_response(['ok' => false, 'error' => $e->getMessage()], 500);
}
