<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/php/bootstrap.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    $meta = App\DatasetProfile::publicMeta();
    $meta['ok'] = true;
    $meta['sqlite'] = App\DatasetProfile::sqliteRelativePath();
    $meta['profile_file'] = 'php/config/profiles/' . App\DatasetProfile::id() . '.json';
    app_json_response($meta);
} catch (Throwable $e) {
    app_json_response(['ok' => false, 'error' => $e->getMessage()], 500);
}
