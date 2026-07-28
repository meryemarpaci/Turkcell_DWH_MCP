<?php

declare(strict_types=1);

namespace App\Tools;

use App\SemanticConfig;

final class SchemaTool
{
    private static ?array $schemaCache = null;
    private static ?string $promptBlockCache = null;

    public function listSchema(?array $tables = null): array
    {
        $allowed = SemanticConfig::allowedTables();
        $descriptions = SemanticConfig::all()['table_descriptions'] ?? [];
        $target = $tables ? array_values(array_intersect($tables, $allowed)) : $allowed;

        if ($tables === null && self::$schemaCache !== null) {
            return self::$schemaCache;
        }

        // Disk cache for full schema (avoids COUNT(*) on every HTTP request)
        if ($tables === null) {
            $cached = $this->readDiskCache();
            if ($cached !== null) {
                self::$schemaCache = $cached;
                return $cached;
            }
        }

        $pdo = Database::pdo();
        $out = [];
        foreach ($target as $name) {
            $cols = $pdo->query('PRAGMA table_info(' . $name . ')')->fetchAll();
            $count = (int) $pdo->query('SELECT COUNT(*) AS n FROM ' . $name)->fetchColumn();
            $out[$name] = [
                'description' => $descriptions[$name] ?? '',
                'row_count' => $count,
                'columns' => array_map(static function (array $c): array {
                    return [
                        'name' => $c['name'],
                        'type' => $c['type'],
                        'nullable' => !(bool) $c['notnull'],
                        'pk' => (bool) $c['pk'],
                    ];
                }, $cols),
            ];
        }

        $payload = [
            'ok' => true,
            'tables' => $out,
            'joins' => SemanticConfig::joins(),
            'metrics' => SemanticConfig::metrics(),
            'filter_hints' => SemanticConfig::all()['filter_hints'] ?? [],
        ];
        if ($tables === null) {
            self::$schemaCache = $payload;
            $this->writeDiskCache($payload);
        }
        return $payload;
    }

    public function schemaPromptBlock(bool $compact = true): string
    {
        if ($compact && self::$promptBlockCache !== null) {
            return self::$promptBlockCache;
        }
        $schema = $this->listSchema();
        $lines = ['# DWH SCHEMA (names/cols only — no row dumps)', ''];
        foreach ($schema['tables'] as $table => $info) {
            $colNames = array_map(static fn (array $c): string => $c['name'], $info['columns']);
            // Single line per table keeps system prompt small
            $lines[] = "- {$table} (" . (int) ($info['row_count'] ?? 0) . '): ' . implode(', ', $colNames);
        }
        $lines[] = '';
        $lines[] = '# JOINS';
        foreach ($schema['joins'] as $j) {
            $lines[] = "- {$j['id']}: {$j['left_table']}.{$j['left_key']}={$j['right_table']}.{$j['right_key']}";
        }
        $lines[] = '';
        $lines[] = '# METRICS';
        foreach ($schema['metrics'] as $m) {
            $lines[] = "- {$m['id']}: {$m['expression']}";
        }
        $lines[] = '';
        $lines[] = '# FILTERS';
        foreach ($schema['filter_hints'] as $h) {
            $lines[] = "- {$h['field']} e.g. {$h['example']}";
        }
        $block = implode("\n", $lines);
        if ($compact) {
            self::$promptBlockCache = $block;
        }
        return $block;
    }

    public function proposeTables(string $intentHint): array
    {
        $hint = mb_strtolower($intentHint);
        $scores = [];
        foreach (SemanticConfig::allowedTables() as $t) {
            $scores[$t] = 0;
        }
        foreach (\App\DatasetProfile::intentTableMap() as $row) {
            $pattern = (string) ($row['pattern'] ?? '');
            $tables = $row['tables'] ?? [];
            if ($pattern === '' || !is_array($tables)) {
                continue;
            }
            if (@preg_match('/' . $pattern . '/u', $hint) === 1) {
                foreach ($tables as $t) {
                    $t = (string) $t;
                    if (isset($scores[$t])) {
                        $scores[$t] += 2;
                    }
                }
            }
        }
        arsort($scores);
        $picked = [];
        foreach ($scores as $t => $s) {
            if ($s > 0) {
                $picked[] = $t;
            }
        }
        if ($picked === []) {
            $picked = \App\DatasetProfile::defaultTables();
            if ($picked === []) {
                $picked = array_slice(SemanticConfig::allowedTables(), 0, 4);
            }
        }
        return ['ok' => true, 'tables' => array_values(array_unique($picked)), 'hint' => $intentHint];
    }

    private function sqlitePath(): string
    {
        $rel = \App\DatasetProfile::sqliteRelativePath();
        return APP_ROOT . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel);
    }

    private function cachePath(): string
    {
        $dir = APP_ROOT . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'runtime';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $id = preg_replace('/[^a-zA-Z0-9_\-]/', '', \App\DatasetProfile::id()) ?: 'dataset';
        return $dir . DIRECTORY_SEPARATOR . 'schema_cache_' . $id . '.json';
    }

    private function readDiskCache(): ?array
    {
        $path = $this->cachePath();
        $db = $this->sqlitePath();
        if (!is_file($path) || !is_file($db)) {
            return null;
        }
        if (filemtime($path) < filemtime($db)) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function writeDiskCache(array $payload): void
    {
        @file_put_contents(
            $this->cachePath(),
            json_encode($payload, JSON_UNESCAPED_UNICODE)
        );
    }
}
