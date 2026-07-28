<?php

declare(strict_types=1);

namespace App;

/** Structured pipeline log entries for browser DevTools. */
final class AgentLog
{
    /** @var list<array<string,mixed>> */
    private array $entries = [];
    private float $t0;

    public function __construct()
    {
        $this->t0 = microtime(true);
    }

    public function add(string $stage, array $data = []): void
    {
        $this->entries[] = array_merge([
            't_ms' => (int) round((microtime(true) - $this->t0) * 1000),
            'stage' => $stage,
        ], $data);
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return $this->entries;
    }

    public static function preview(?string $text, int $max = 160): string
    {
        $text = trim((string) $text);
        if ($text === '') {
            return '';
        }
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        return mb_substr($text, 0, $max) . '…';
    }

    public static function bytes(mixed $payload): int
    {
        if (is_string($payload)) {
            return strlen($payload);
        }
        return strlen((string) json_encode($payload, JSON_UNESCAPED_UNICODE));
    }
}
