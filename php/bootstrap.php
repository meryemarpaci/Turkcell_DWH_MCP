<?php

declare(strict_types=1);

/**
 * Application bootstrap: env, paths, autoload.
 */

define('APP_ROOT', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix)));
    $path = APP_ROOT . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . $relative . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

function app_load_env(string $path): void
{
    if (!is_file($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ($key !== '') {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}

app_load_env(APP_ROOT . DIRECTORY_SEPARATOR . '.env');

function app_env(string $key, ?string $default = null): ?string
{
    $v = $_ENV[$key] ?? getenv($key);
    if ($v === false || $v === null || $v === '') {
        return $default;
    }
    return (string) $v;
}

function app_set_env_runtime(string $key, string $value): void
{
    putenv("$key=$value");
    $_ENV[$key] = $value;
}

/** Persist short-lived runtime flags (e.g. Gemini quota cooldown). */
function app_runtime_flag_path(string $name): string
{
    $dir = APP_ROOT . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'runtime';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir . DIRECTORY_SEPARATOR . $name . '.flag';
}

function app_runtime_get(string $name, ?string $default = null): ?string
{
    $path = app_runtime_flag_path($name);
    if (!is_file($path)) {
        return $default;
    }
    $v = trim((string) file_get_contents($path));
    return $v !== '' ? $v : $default;
}

function app_runtime_set(string $name, string $value): void
{
    file_put_contents(app_runtime_flag_path($name), $value);
}

function app_json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function app_read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}
