<?php

declare(strict_types=1);

/**
 * Router for: php -S localhost:8080 router.php
 */
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
$root = __DIR__;

if (str_starts_with($uri, '/api/')) {
    $file = $root . $uri;
    if (is_file($file)) {
        require $file;
        return true;
    }
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'API not found']);
    return true;
}

$public = $root . '/public';
$path = $public . ($uri === '/' ? '/index.html' : $uri);
if ($uri !== '/' && is_file($path)) {
    $ext = pathinfo($path, PATHINFO_EXTENSION);
    $types = [
        'css' => 'text/css',
        'js' => 'application/javascript',
        'html' => 'text/html',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'ico' => 'image/x-icon',
    ];
    if (isset($types[$ext])) {
        header('Content-Type: ' . $types[$ext]);
    }
    readfile($path);
    return true;
}

if (is_file($public . '/index.html')) {
    header('Content-Type: text/html; charset=utf-8');
    readfile($public . '/index.html');
    return true;
}

return false;
