<?php

declare(strict_types=1);

namespace App;

/** Thin facade — all semantic data comes from the active DatasetProfile. */
final class SemanticConfig
{
    public static function all(): array
    {
        return DatasetProfile::semantic();
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
