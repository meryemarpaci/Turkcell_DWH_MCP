<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/php/bootstrap.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    @set_time_limit(300);
    $skipFlag = APP_ROOT . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'gemini_skip_until.flag';
    if (is_file($skipFlag)) {
        @unlink($skipFlag);
    }

    $body = app_read_json_body();
    $message = trim((string) ($body['message'] ?? ''));
    $sessionId = (string) ($body['session_id'] ?? bin2hex(random_bytes(8)));
    $t0 = microtime(true);
    $apiLog = new App\AgentLog();

    if ($message === '') {
        app_json_response(['ok' => false, 'error' => 'message required', 'logs' => []], 400);
    }

    $apiLog->add('api_request', [
        'session_id' => $sessionId,
        'user_message' => App\AgentLog::preview($message, 240),
    ]);

    $models = App\GeminiEngine::modelCascade();
    $apiLog->add('provider_plan', [
        'order' => $models,
        'note' => 'Gemini only — retry + model cascade on high demand',
    ]);

    $errors = [];
    foreach ($models as $i => $model) {
        $apiLog->add('provider_attempt', [
            'index' => $i,
            'provider_slot' => 'gemini',
            'model' => $model,
        ]);
        try {
            $engine = new App\GeminiEngine(null, $model);
            $apiLog->add('provider_engine_ready', ['engine' => $engine->name()]);
            $result = (new App\AgentOrchestrator($engine))->handle($sessionId, $message);
            $result['logs'] = array_merge($apiLog->all(), $result['logs'] ?? []);
            $result['total_ms'] = (int) round((microtime(true) - $t0) * 1000);
            if ($i > 0) {
                $result['model_fallback'] = $model;
                $result['primary_error'] = $errors[0] ?? null;
            }
            $result['logs'][] = [
                't_ms' => $result['total_ms'],
                'stage' => 'api_success',
                'engine' => $engine->name(),
                'type' => $result['type'] ?? null,
                'llm_calls' => $result['llm_calls'] ?? null,
            ];
            app_json_response($result);
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            $errors[] = $msg;
            $apiLog->add('provider_failed', [
                'provider_slot' => 'gemini',
                'model' => $model,
                'error' => $msg,
            ]);
            if (!App\GeminiEngine::shouldTryNextModel($msg)) {
                break;
            }
        }
    }

    $friendly = 'Gemini şu an yoğun veya kotası dolu. 30–60 sn sonra tekrar dener misin?';
    if ($errors !== [] && !App\GeminiEngine::isTransientError($errors[array_key_last($errors)])) {
        $friendly = $errors[array_key_last($errors)];
    }

    app_json_response([
        'ok' => false,
        'error' => $friendly,
        'errors' => $errors,
        'provider' => 'gemini',
        'logs' => $apiLog->all(),
        'total_ms' => (int) round((microtime(true) - $t0) * 1000),
    ], 502);
} catch (Throwable $e) {
    app_json_response([
        'ok' => false,
        'error' => $e->getMessage(),
        'logs' => [
            ['t_ms' => 0, 'stage' => 'fatal', 'error' => $e->getMessage()],
        ],
        'trace' => app_env('APP_DEBUG', '0') === '1' ? $e->getTraceAsString() : null,
    ], 500);
}
