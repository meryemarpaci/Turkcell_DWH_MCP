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

$stream = false;
try {
    @set_time_limit(300);
    @ini_set('output_buffering', 'off');
    @ini_set('zlib.output_compression', '0');
    while (ob_get_level() > 0) {
        @ob_end_flush();
    }

    $body = app_read_json_body();
    $message = trim((string) ($body['message'] ?? ''));
    $sessionId = (string) ($body['session_id'] ?? bin2hex(random_bytes(8)));
    $stream = !empty($body['stream']);
    $t0 = microtime(true);

    if ($message === '') {
        if ($stream) {
            app_ndjson_headers();
            app_ndjson_emit(['event' => 'error', 'error' => 'message required']);
            exit;
        }
        app_json_response(['ok' => false, 'error' => 'message required', 'logs' => []], 400);
    }

    $emit = null;
    if ($stream) {
        app_ndjson_headers();
        $emit = static function (array $entry): void {
            app_ndjson_emit(['event' => 'log', 'log' => $entry]);
        };
    }

    $apiLog = new App\AgentLog($emit);
    $apiLog->add('api_request', [
        'session_id' => $sessionId,
        'user_message' => App\AgentLog::preview($message, 240),
        'stream' => $stream,
    ]);

    $errors = [];
    $skipGeminiUntil = (int) (app_runtime_get('gemini_skip_until') ?? '0');
    $now = time();
    $useGemini = $now >= $skipGeminiUntil;
    if (!$useGemini) {
        $apiLog->add('gemini_cooldown', [
            'skip_until' => $skipGeminiUntil,
            'seconds_left' => max(0, $skipGeminiUntil - $now),
            'note' => 'Skipping Gemini — free-tier quota cooldown; trying Groq',
        ]);
    }

    $models = $useGemini ? App\GeminiEngine::modelCascade() : [];
    $apiLog->add('provider_plan', [
        'gemini_models' => $models,
        'groq_fallback' => app_env('GROQ_API_KEY', '') !== '' || app_env('GROK_API_KEY', '') !== '',
        'note' => 'Gemini first; on quota/rate-limit → Groq',
    ]);

    $finishOk = static function (array $result) use ($stream, $apiLog, $t0): void {
        $result['logs'] = array_merge($apiLog->all(), $result['logs'] ?? []);
        $result['total_ms'] = (int) round((microtime(true) - $t0) * 1000);
        if ($stream) {
            app_ndjson_emit(['event' => 'result', 'data' => $result]);
            exit;
        }
        app_json_response($result);
    };

    foreach ($models as $i => $model) {
        $apiLog->add('provider_attempt', [
            'index' => $i,
            'provider_slot' => 'gemini',
            'model' => $model,
        ]);
        try {
            $engine = new App\GeminiEngine(null, $model);
            $apiLog->add('provider_engine_ready', ['engine' => $engine->name()]);
            $result = (new App\AgentOrchestrator($engine, $emit))->handle($sessionId, $message);
            if ($i > 0) {
                $result['model_fallback'] = $model;
                $result['primary_error'] = $errors[0] ?? null;
            }
            $result['logs'][] = [
                't_ms' => (int) round((microtime(true) - $t0) * 1000),
                'stage' => 'api_success',
                'engine' => $engine->name(),
                'type' => $result['type'] ?? null,
                'llm_calls' => $result['llm_calls'] ?? null,
            ];
            $finishOk($result);
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            $errors[] = $msg;
            $apiLog->add('provider_failed', [
                'provider_slot' => 'gemini',
                'model' => $model,
                'error' => $msg,
            ]);
            if (App\GeminiEngine::isQuotaError($msg)) {
                $wait = App\GeminiEngine::retryAfterSeconds($msg) ?? 60;
                $wait = min(max($wait, 30), 120);
                app_runtime_set('gemini_skip_until', (string) (time() + $wait));
                $apiLog->add('gemini_quota_cooldown_set', ['seconds' => $wait]);
            }
            if (!App\GeminiEngine::shouldTryNextModel($msg)) {
                break;
            }
        }
    }

    $groqKey = (string) (app_env('GROQ_API_KEY') ?: app_env('GROK_API_KEY', ''));
    if ($groqKey !== '') {
        $apiLog->add('provider_attempt', [
            'provider_slot' => 'groq',
            'model' => (string) app_env('GROQ_MODEL', 'llama-3.1-8b-instant'),
        ]);
        try {
            $engine = new App\GroqEngine();
            $apiLog->add('provider_engine_ready', ['engine' => $engine->name()]);
            $result = (new App\AgentOrchestrator($engine, $emit))->handle($sessionId, $message);
            $result['provider_fallback'] = 'groq';
            if ($errors !== []) {
                $result['gemini_errors'] = $errors;
            }
            $result['logs'][] = [
                't_ms' => (int) round((microtime(true) - $t0) * 1000),
                'stage' => 'api_success',
                'engine' => $engine->name(),
                'type' => $result['type'] ?? null,
                'llm_calls' => $result['llm_calls'] ?? null,
            ];
            $finishOk($result);
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
            $apiLog->add('provider_failed', [
                'provider_slot' => 'groq',
                'error' => $e->getMessage(),
            ]);
        }
    }

    $friendly = 'AI kotası dolu (Gemini + Groq). Birkaç dakika sonra tekrar dene veya Gemini billing / yeni API key kontrol et.';
    $last = $errors !== [] ? $errors[array_key_last($errors)] : '';
    if ($last !== '' && !App\GeminiEngine::isTransientError($last) && !str_starts_with($last, 'Groq')) {
        $friendly = $last;
    } elseif ($last !== '' && str_starts_with($last, 'Groq')) {
        $friendly = 'Gemini kotası dolu; Groq da yanıt veremedi: ' . $last;
    }

    $fail = [
        'ok' => false,
        'error' => $friendly,
        'errors' => $errors,
        'provider' => 'exhausted',
        'logs' => $apiLog->all(),
        'total_ms' => (int) round((microtime(true) - $t0) * 1000),
    ];
    if ($stream) {
        app_ndjson_emit(['event' => 'error', 'data' => $fail, 'error' => $friendly]);
        exit;
    }
    app_json_response($fail, 502);
} catch (Throwable $e) {
    $payload = [
        'ok' => false,
        'error' => $e->getMessage(),
        'logs' => [
            ['t_ms' => 0, 'stage' => 'fatal', 'error' => $e->getMessage()],
        ],
        'trace' => app_env('APP_DEBUG', '0') === '1' ? $e->getTraceAsString() : null,
    ];
    if ($stream) {
        if (!headers_sent()) {
            app_ndjson_headers();
        }
        app_ndjson_emit(['event' => 'error', 'data' => $payload, 'error' => $e->getMessage()]);
        exit;
    }
    app_json_response($payload, 500);
}

function app_ndjson_headers(): void
{
    header('Content-Type: application/x-ndjson; charset=utf-8');
    header('Cache-Control: no-store, no-cache');
    header('X-Accel-Buffering: no');
}

/** @param array<string,mixed> $payload */
function app_ndjson_emit(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    if (function_exists('ob_flush')) {
        @ob_flush();
    }
    flush();
}
