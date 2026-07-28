<?php

declare(strict_types=1);

namespace App;

/** Structured pipeline log — optionally emits each entry live (NDJSON stream). */
final class AgentLog
{
    /** @var list<array<string,mixed>> */
    private array $entries = [];
    private float $t0;
    /** @var null|callable(array<string,mixed>):void */
    private $onEmit;

    public function __construct(?callable $onEmit = null)
    {
        $this->t0 = microtime(true);
        $this->onEmit = $onEmit;
    }

    public function add(string $stage, array $data = []): void
    {
        $entry = array_merge([
            't_ms' => (int) round((microtime(true) - $this->t0) * 1000),
            'stage' => $stage,
        ], $data);
        $this->entries[] = $entry;
        if ($this->onEmit !== null) {
            ($this->onEmit)($entry);
        }
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
