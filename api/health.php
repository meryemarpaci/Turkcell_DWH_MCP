<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/php/bootstrap.php';

try {
    $schema = (new App\Tools\SchemaTool())->listSchema();
    app_json_response([
        'ok' => true,
        'php' => PHP_VERSION,
        'db_tables' => array_keys($schema['tables'] ?? []),
        'gemini_key_set' => (app_env('GEMINI_API_KEY') ?? '') !== '',
        'gemini_model' => app_env('GEMINI_MODEL', 'gemini-2.5-flash'),
        'gemini_cascade' => App\GeminiEngine::modelCascade(),
        'order' => 'gemini-only (cascade)',
    ]);
} catch (Throwable $e) {
    app_json_response(['ok' => false, 'error' => $e->getMessage()], 500);
}
