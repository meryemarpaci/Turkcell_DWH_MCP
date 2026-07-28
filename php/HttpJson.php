<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

/** Shared HTTPS JSON POST helper. */
final class HttpJson
{
    public static function post(string $url, array $body, array $headers = []): array
    {
        $json = json_encode($body, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('JSON encode failed');
        }

        $headerLines = array_merge(['Content-Type: application/json'], $headers);
        $ca = APP_ROOT . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . 'cacert.pem';

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $opts = [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => $headerLines,
                CURLOPT_POSTFIELDS => $json,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 120,
            ];
            if (is_file($ca)) {
                $opts[CURLOPT_CAINFO] = $ca;
                $opts[CURLOPT_SSL_VERIFYPEER] = true;
            } elseif (app_env('APP_DEBUG', '0') === '1') {
                $opts[CURLOPT_SSL_VERIFYPEER] = false;
            }
            curl_setopt_array($ch, $opts);
            $raw = curl_exec($ch);
            $errno = curl_errno($ch);
            $err = curl_error($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($errno) {
                throw new RuntimeException('cURL error: ' . $err);
            }
        } else {
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => implode("\r\n", $headerLines) . "\r\n",
                    'content' => $json,
                    'timeout' => 120,
                    'ignore_errors' => true,
                ],
            ]);
            $raw = file_get_contents($url, false, $ctx);
            $status = 0;
            if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
                $status = (int) $m[1];
            }
        }

        if ($raw === false || $raw === '') {
            throw new RuntimeException('Empty HTTP response');
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid JSON: ' . substr((string) $raw, 0, 400));
        }
        return ['status' => $status, 'body' => $decoded, 'raw' => $raw];
    }
}
