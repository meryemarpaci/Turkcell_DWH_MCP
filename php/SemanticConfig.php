<?php

declare(strict_types=1);

namespace App;

final class SemanticConfig
{
    private static ?array $data = null;

    public static function all(): array
    {
        if (self::$data !== null) {
            return self::$data;
        }
        $path = APP_ROOT . '/php/config/semantic.json';
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException('semantic.json missing');
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('semantic.json invalid');
        }
        self::$data = $decoded;
        return self::$data;
    }

    /** @return list<string> */
    public static function allowedTables(): array
    {
        return self::all()['allowed_tables'] ?? [];
    }

    public static function joins(): array
    {
        return self::all()['joins'] ?? [];
    }

    public static function metrics(): array
    {
        return self::all()['metrics'] ?? [];
    }

    public static function joinById(string $id): ?array
    {
        foreach (self::joins() as $j) {
            if (($j['id'] ?? '') === $id) {
                return $j;
            }
        }
        return null;
    }
}
