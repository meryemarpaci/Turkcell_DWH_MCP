<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/php/bootstrap.php';

try {
    $schema = (new App\Tools\SchemaTool())->listSchema();
    $profile = App\DatasetProfile::publicMeta();
    app_json_response([
        'ok' => true,
        'php' => PHP_VERSION,
        'profile' => $profile,
        'db_tables' => array_keys($schema['tables'] ?? []),
        'sqlite' => App\DatasetProfile::sqliteRelativePath(),
        'gemini_key_set' => (app_env('GEMINI_API_KEY') ?? '') !== '',
        'gemini_model' => app_env('GEMINI_MODEL', 'gemini-3-flash-preview'),
        'gemini_cascade' => App\GeminiEngine::modelCascade(),
        'groq_key_set' => (app_env('GROQ_API_KEY') ?: app_env('GROK_API_KEY', '')) !== '',
        'order' => 'gemini cascade → groq on quota',
    ]);
} catch (Throwable $e) {
    app_json_response(['ok' => false, 'error' => $e->getMessage()], 500);
}
