<?php
require_once dirname(__DIR__) . '/php/bootstrap.php';
echo 'models=' . implode(',', App\GeminiEngine::modelCascade()) . PHP_EOL;
echo 'GEMINI_MODEL=' . app_env('GEMINI_MODEL') . PHP_EOL;
