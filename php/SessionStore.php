<?php

declare(strict_types=1);

namespace App;

final class SessionStore
{
    private string $dir;

    public function __construct()
    {
        $this->dir = APP_ROOT . '/storage/sessions';
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0777, true);
        }
    }

    public function load(string $sessionId): array
    {
        $path = $this->path($sessionId);
        if (!is_file($path)) {
            return [
                'id' => $sessionId,
                'messages' => [],
                'reports' => [],
                'pending_clarify' => null,
            ];
        }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : ['id' => $sessionId, 'messages' => [], 'reports' => []];
    }

    public function save(string $sessionId, array $state): void
    {
        $state['id'] = $sessionId;
        $state['updated_at'] = date('c');
        file_put_contents(
            $this->path($sessionId),
            json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
    }

    private function path(string $sessionId): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionId) ?: 'default';
        return $this->dir . '/' . $safe . '.json';
    }
}
