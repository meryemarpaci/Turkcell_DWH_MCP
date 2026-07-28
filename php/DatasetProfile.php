<?php

declare(strict_types=1);

namespace App;

/**
 * Dataset profile loader — data-independent config + optional SQLite auto-discovery.
 *
 * Chat end-users never edit JSON.
 * Ops: set DWH_SQLITE_PATH (+ optional thin profile). Discovery fills tables/joins/cols.
 * Rich profile fields (aliases, prompt) override / extend discovery.
 */
final class DatasetProfile
{
    private static ?array $profile = null;
    private static ?string $loadedId = null;

    public static function id(): string
    {
        return (string) (self::get()['id'] ?? 'olist');
    }

    /** @return array<string,mixed> */
    public static function get(): array
    {
        $want = trim((string) app_env('DWH_PROFILE', 'olist'));
        if ($want === '') {
            $want = 'olist';
        }
        if (self::$profile !== null && self::$loadedId === $want) {
            return self::$profile;
        }

        $rawProfile = self::loadRaw($want);
        $auto = !array_key_exists('auto_discover', $rawProfile) || !empty($rawProfile['auto_discover']);

        if ($auto) {
            $abs = self::absoluteSqlitePath(self::sqliteRelativePathFromRaw($rawProfile));
            $discovered = SchemaDiscoverer::fromSqlite($abs);
            $rawProfile = self::mergeDiscovery($discovered, $rawProfile);
        }

        self::$profile = self::normalize($rawProfile);
        self::$loadedId = $want;
        return self::$profile;
    }

    /** Peek sqlite path without triggering full get()/discover cycle. */
    public static function sqliteRelativePath(): string
    {
        $env = trim((string) app_env('DWH_SQLITE_PATH', ''));
        if ($env !== '') {
            return $env;
        }
        if (self::$profile !== null) {
            $p = (string) (self::$profile['sqlite'] ?? '');
            if ($p !== '') {
                return $p;
            }
        }
        $want = trim((string) app_env('DWH_PROFILE', 'olist')) ?: 'olist';
        $raw = self::loadRawFileOnly($want);
        return self::sqliteRelativePathFromRaw($raw);
    }

    public static function pathFor(string $id): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '', $id) ?: 'olist';
        return APP_ROOT . '/php/config/profiles/' . $safe . '.json';
    }

    /** Semantic slice used by SqlGuard / SchemaTool. */
    public static function semantic(): array
    {
        $p = self::get();
        return [
            'allowed_tables' => $p['allowed_tables'] ?? [],
            'joins' => $p['joins'] ?? [],
            'metrics' => $p['metrics'] ?? [],
            'table_descriptions' => $p['table_descriptions'] ?? [],
            'filter_hints' => $p['filter_hints'] ?? [],
        ];
    }

    /** @return array<string,mixed> */
    public static function calendar(): array
    {
        return self::get()['calendar'] ?? [];
    }

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return self::get()['defaults'] ?? [];
    }

    /** @return list<array<string,mixed>> */
    public static function aliases(): array
    {
        $a = self::get()['aliases'] ?? [];
        return is_array($a) ? $a : [];
    }

    /** @return list<array<string,mixed>> */
    public static function intentTableMap(): array
    {
        $m = self::get()['intent_table_map'] ?? [];
        return is_array($m) ? $m : [];
    }

    /** @return list<string> */
    public static function defaultTables(): array
    {
        $t = self::get()['default_tables'] ?? [];
        return is_array($t) ? array_values(array_map('strval', $t)) : [];
    }

    /** @return array<string,string> */
    public static function clarifyCues(): array
    {
        $c = self::get()['clarify_cues'] ?? [];
        return is_array($c) ? $c : [];
    }

    /** @return array<string,mixed> */
    public static function prompt(): array
    {
        $p = self::get()['prompt'] ?? [];
        return is_array($p) ? $p : [];
    }

    /** Public card for UI / health. */
    public static function publicMeta(): array
    {
        $p = self::get();
        $prompt = self::prompt();
        return [
            'id' => $p['id'] ?? self::id(),
            'display_name' => $p['display_name'] ?? self::id(),
            'description' => $p['description'] ?? '',
            'ui_examples' => $prompt['ui_examples'] ?? [],
            'ui_subtitle' => $prompt['ui_subtitle'] ?? '',
            'tables' => $p['allowed_tables'] ?? [],
            'auto_discover' => !empty($p['auto_discover']) || !array_key_exists('auto_discover', $p),
            'discovery' => $p['discovery'] ?? null,
        ];
    }

    /** @return array<string,mixed> */
    private static function loadRaw(string $want): array
    {
        $path = self::pathFor($want);
        if (!is_file($path)) {
            // Minimal auto profile: only sqlite from env
            return [
                'id' => $want,
                'display_name' => $want,
                'auto_discover' => true,
                'sqlite' => self::sqliteRelativePathFromRaw([]),
                'prompt' => [
                    'system_fragment' => 'Domain: auto-discovered SQLite DWH. Answer in Turkish. Use discovered joins/metrics.',
                    'ui_examples' => ['Bu ayki özet nasıl?', 'İlk 20 satırı göster'],
                    'ui_subtitle' => 'Otomatik keşfedilen veri seti',
                ],
            ];
        }
        $raw = file_get_contents($path);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            throw new \RuntimeException("Invalid profile JSON: {$path}");
        }
        if (!isset($decoded['id'])) {
            $decoded['id'] = $want;
        }
        return $decoded;
    }

    /** @return array<string,mixed> */
    private static function loadRawFileOnly(string $want): array
    {
        $path = self::pathFor($want);
        if (!is_file($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $raw */
    private static function sqliteRelativePathFromRaw(array $raw): string
    {
        $env = trim((string) app_env('DWH_SQLITE_PATH', ''));
        if ($env !== '') {
            return $env;
        }
        $from = (string) ($raw['sqlite'] ?? '');
        return $from !== '' ? $from : 'Data/olist_dwh.sqlite';
    }

    private static function absoluteSqlitePath(string $rel): string
    {
        return APP_ROOT . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel);
    }

    /**
     * Profile JSON wins on non-empty explicit fields; discovery fills gaps.
     *
     * @param array<string,mixed> $discovered
     * @param array<string,mixed> $profile
     * @return array<string,mixed>
     */
    private static function mergeDiscovery(array $discovered, array $profile): array
    {
        $profile['discovery'] = $discovered['discovery'] ?? null;

        if (empty($profile['allowed_tables'])) {
            $profile['allowed_tables'] = $discovered['allowed_tables'] ?? [];
        }
        if (empty($profile['joins'])) {
            $profile['joins'] = $discovered['joins'] ?? [];
        } else {
            // keep explicit joins; append discovered that don't duplicate
            $profile['joins'] = self::mergeJoinLists($profile['joins'], $discovered['joins'] ?? []);
        }
        if (empty($profile['metrics'])) {
            $profile['metrics'] = $discovered['metrics'] ?? [];
        }
        if (empty($profile['table_descriptions'])) {
            $profile['table_descriptions'] = $discovered['table_descriptions'] ?? [];
        }
        if (empty($profile['filter_hints'])) {
            $profile['filter_hints'] = $discovered['filter_hints'] ?? [];
        }
        if (empty($profile['default_tables'])) {
            $profile['default_tables'] = $discovered['default_tables'] ?? [];
        }

        $cal = is_array($profile['calendar'] ?? null) ? $profile['calendar'] : [];
        $dcal = is_array($discovered['calendar'] ?? null) ? $discovered['calendar'] : [];
        if (empty($cal['fact_table']) && !empty($dcal['fact_table'])) {
            $cal['fact_table'] = $dcal['fact_table'];
        }
        if (empty($cal['date_column']) && !empty($dcal['date_column'])) {
            $cal['date_column'] = $dcal['date_column'];
        }
        foreach (['min_rows_per_month', 'fallback_min', 'fallback_max'] as $k) {
            if (!isset($cal[$k]) && isset($dcal[$k])) {
                $cal[$k] = $dcal[$k];
            }
        }
        $profile['calendar'] = $cal;

        if (!isset($profile['auto_discover'])) {
            $profile['auto_discover'] = true;
        }
        return $profile;
    }

    /**
     * @param list<array<string,mixed>> $primary
     * @param list<array<string,mixed>> $extra
     * @return list<array<string,mixed>>
     */
    private static function mergeJoinLists(array $primary, array $extra): array
    {
        $seen = [];
        $out = [];
        foreach (array_merge($primary, $extra) as $j) {
            if (!is_array($j)) {
                continue;
            }
            $sig = ($j['left_table'] ?? '') . '|' . ($j['left_key'] ?? '') . '|' . ($j['right_table'] ?? '') . '|' . ($j['right_key'] ?? '');
            if ($sig === '|||' || isset($seen[$sig])) {
                continue;
            }
            $seen[$sig] = true;
            $out[] = $j;
        }
        return $out;
    }

    /** @param array<string,mixed> $p */
    private static function normalize(array $p): array
    {
        if (!isset($p['id']) || $p['id'] === '') {
            $p['id'] = 'dataset';
        }
        if (!isset($p['allowed_tables']) || !is_array($p['allowed_tables'])) {
            $p['allowed_tables'] = [];
        }
        return $p;
    }
}
