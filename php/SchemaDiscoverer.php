<?php

declare(strict_types=1);

namespace App;

use PDO;
use Throwable;

/**
 * Introspect SQLite: tables, columns, FK joins, calendar/metric hints.
 * Used so a new dataset does not require hand-writing full profile JSON.
 */
final class SchemaDiscoverer
{
    /**
     * @return array{
     *   allowed_tables:list<string>,
     *   joins:list<array<string,mixed>>,
     *   metrics:list<array<string,mixed>>,
     *   table_descriptions:array<string,string>,
     *   filter_hints:list<array<string,string>>,
     *   calendar:array<string,mixed>,
     *   default_tables:list<string>,
     *   discovery:array<string,mixed>
     * }
     */
    public static function fromSqlite(string $absoluteSqlitePath): array
    {
        if (!is_file($absoluteSqlitePath)) {
            return self::emptyResult('sqlite missing: ' . $absoluteSqlitePath);
        }

        try {
            $pdo = new PDO('sqlite:' . $absoluteSqlitePath, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (Throwable $e) {
            return self::emptyResult('pdo failed: ' . $e->getMessage());
        }

        $tables = [];
        foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name") as $row) {
            $name = (string) ($row['name'] ?? '');
            if ($name !== '' && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
                $tables[] = $name;
            }
        }

        $columnsByTable = [];
        $pkByTable = [];
        foreach ($tables as $t) {
            $cols = $pdo->query('PRAGMA table_info(' . $t . ')')->fetchAll();
            $columnsByTable[$t] = [];
            $pkByTable[$t] = [];
            foreach ($cols as $c) {
                $cn = (string) ($c['name'] ?? '');
                if ($cn === '') {
                    continue;
                }
                $columnsByTable[$t][] = [
                    'name' => $cn,
                    'type' => (string) ($c['type'] ?? ''),
                    'pk' => (bool) ($c['pk'] ?? false),
                ];
                if (!empty($c['pk'])) {
                    $pkByTable[$t][] = $cn;
                }
            }
        }

        $joins = self::joinsFromForeignKeys($pdo, $tables);
        $joins = self::mergeJoins($joins, self::joinsFromNameHeuristics($tables, $columnsByTable, $pkByTable));

        $descriptions = [];
        foreach ($tables as $t) {
            $n = count($columnsByTable[$t] ?? []);
            $descriptions[$t] = "Auto-discovered table ({$n} columns)";
        }

        $calendar = self::inferCalendar($tables, $columnsByTable);
        $metrics = self::inferMetrics($tables, $columnsByTable);
        $filterHints = self::inferFilterHints($tables, $columnsByTable);
        $defaultTables = self::pickDefaultTables($tables);

        return [
            'allowed_tables' => $tables,
            'joins' => $joins,
            'metrics' => $metrics,
            'table_descriptions' => $descriptions,
            'filter_hints' => $filterHints,
            'calendar' => $calendar,
            'default_tables' => $defaultTables,
            'discovery' => [
                'source' => 'sqlite_pragma',
                'table_count' => count($tables),
                'join_count' => count($joins),
                'fk_join_count' => count(array_filter($joins, static fn ($j) => ($j['source'] ?? '') === 'fk')),
                'heuristic_join_count' => count(array_filter($joins, static fn ($j) => ($j['source'] ?? '') === 'heuristic')),
            ],
        ];
    }

    /** @param list<string> $tables */
    private static function joinsFromForeignKeys(PDO $pdo, array $tables): array
    {
        $out = [];
        $tableSet = array_fill_keys($tables, true);
        foreach ($tables as $t) {
            try {
                $fks = $pdo->query('PRAGMA foreign_key_list(' . $t . ')')->fetchAll();
            } catch (Throwable) {
                continue;
            }
            foreach ($fks as $fk) {
                $parent = (string) ($fk['table'] ?? '');
                $from = (string) ($fk['from'] ?? '');
                $to = (string) ($fk['to'] ?? '');
                if ($parent === '' || $from === '' || $to === '' || !isset($tableSet[$parent])) {
                    continue;
                }
                $id = $t . '__' . $from . '__' . $parent;
                $out[$id] = [
                    'id' => substr(preg_replace('/[^a-zA-Z0-9_]/', '_', $id) ?? $id, 0, 64),
                    'left_table' => $t,
                    'right_table' => $parent,
                    'left_key' => $from,
                    'right_key' => $to,
                    'cardinality' => 'N:1',
                    'description' => "FK {$t}.{$from} → {$parent}.{$to}",
                    'source' => 'fk',
                ];
            }
        }
        return array_values($out);
    }

    /**
     * @param list<string> $tables
     * @param array<string,list<array<string,mixed>>> $columnsByTable
     * @param array<string,list<string>> $pkByTable
     */
    private static function joinsFromNameHeuristics(array $tables, array $columnsByTable, array $pkByTable): array
    {
        $out = [];
        // Map singular-ish names: dim_customer → customer_id expect
        foreach ($tables as $left) {
            $leftCols = array_column($columnsByTable[$left] ?? [], 'name');
            foreach ($tables as $right) {
                if ($left === $right) {
                    continue;
                }
                $rightPks = $pkByTable[$right] ?? [];
                $rightBase = self::tableBase($right); // customer from dim_customer
                $candidates = array_values(array_unique(array_filter([
                    $rightBase . '_id',
                    (count($rightPks) === 1 ? $rightPks[0] : null),
                    'id',
                ])));

                foreach ($candidates as $key) {
                    if ($key === null || $key === '') {
                        continue;
                    }
                    if (!in_array($key, $leftCols, true)) {
                        continue;
                    }
                    // right side must have same key or single PK named that way
                    $rightCols = array_column($columnsByTable[$right] ?? [], 'name');
                    $rightKey = in_array($key, $rightCols, true) ? $key : (count($rightPks) === 1 ? $rightPks[0] : null);
                    if ($rightKey === null || !in_array($rightKey, $rightCols, true)) {
                        continue;
                    }
                    // Prefer fact → dim style
                    $id = $left . '_' . $right . '_' . $key;
                    $out[$id] = [
                        'id' => substr(preg_replace('/[^a-zA-Z0-9_]/', '_', $id) ?? $id, 0, 64),
                        'left_table' => $left,
                        'right_table' => $right,
                        'left_key' => $key,
                        'right_key' => $rightKey,
                        'cardinality' => 'N:1',
                        'description' => "Heuristic {$left}.{$key} = {$right}.{$rightKey}",
                        'source' => 'heuristic',
                    ];
                }
            }
        }
        return array_values($out);
    }

    private static function tableBase(string $table): string
    {
        $t = preg_replace('/^(dim_|fact_|bridge_)/i', '', $table) ?? $table;
        // order_items → order_item? keep as-is for *_id: order_id from fact_orders
        if (str_ends_with($t, 'ies')) {
            return substr($t, 0, -3) . 'y';
        }
        if (str_ends_with($t, 's') && !str_ends_with($t, 'ss')) {
            return substr($t, 0, -1);
        }
        return $t;
    }

    /** @param list<array<string,mixed>> $a @param list<array<string,mixed>> $b */
    private static function mergeJoins(array $a, array $b): array
    {
        $seen = [];
        $out = [];
        foreach (array_merge($a, $b) as $j) {
            $sig = ($j['left_table'] ?? '') . '|' . ($j['left_key'] ?? '') . '|' . ($j['right_table'] ?? '') . '|' . ($j['right_key'] ?? '');
            if (isset($seen[$sig])) {
                continue;
            }
            $seen[$sig] = true;
            $out[] = $j;
        }
        return $out;
    }

    /**
     * @param list<string> $tables
     * @param array<string,list<array<string,mixed>>> $columnsByTable
     */
    private static function inferCalendar(array $tables, array $columnsByTable): array
    {
        $dateHints = ['timestamp', 'date', 'datetime', 'created', 'purchase', 'event', 'order_date'];
        $best = null;
        $bestScore = -1;
        foreach ($tables as $t) {
            $isFact = str_starts_with(strtolower($t), 'fact_') || str_contains(strtolower($t), 'order') || str_contains(strtolower($t), 'event');
            foreach ($columnsByTable[$t] ?? [] as $c) {
                $name = strtolower((string) $c['name']);
                $score = 0;
                foreach ($dateHints as $h) {
                    if (str_contains($name, $h)) {
                        $score += 3;
                    }
                }
                if ($isFact) {
                    $score += 2;
                }
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = ['table' => $t, 'column' => (string) $c['name']];
                }
            }
        }
        if ($best === null || $bestScore < 2) {
            return [
                'fact_table' => $tables[0] ?? '',
                'date_column' => '',
                'min_rows_per_month' => 1,
                'fallback_min' => '2000-01-01',
                'fallback_max' => '2099-12-31',
            ];
        }
        return [
            'fact_table' => $best['table'],
            'date_column' => $best['column'],
            'min_rows_per_month' => 1,
            'fallback_min' => '2000-01-01',
            'fallback_max' => '2099-12-31',
        ];
    }

    /**
     * @param list<string> $tables
     * @param array<string,list<array<string,mixed>>> $columnsByTable
     */
    private static function inferMetrics(array $tables, array $columnsByTable): array
    {
        $metrics = [];
        $numericHints = ['price', 'amount', 'value', 'total', 'qty', 'quantity', 'score', 'freight', 'cost', 'revenue', 'gmv'];
        foreach ($tables as $t) {
            if (count($metrics) >= 8) {
                break;
            }
            foreach ($columnsByTable[$t] ?? [] as $c) {
                $name = (string) $c['name'];
                $low = strtolower($name);
                $type = strtolower((string) ($c['type'] ?? ''));
                $isNum = str_contains($type, 'int') || str_contains($type, 'real') || str_contains($type, 'num') || str_contains($type, 'dec');
                $hintHit = false;
                foreach ($numericHints as $h) {
                    if (str_contains($low, $h)) {
                        $hintHit = true;
                        break;
                    }
                }
                if (!$isNum && !$hintHit) {
                    continue;
                }
                if (str_ends_with($low, '_id') || $low === 'id' || str_contains($low, 'zip')) {
                    continue;
                }
                $id = $t . '_' . $name . '_sum';
                $metrics[] = [
                    'id' => substr(preg_replace('/[^a-zA-Z0-9_]/', '_', $id) ?? $id, 0, 48),
                    'name' => "SUM({$t}.{$name})",
                    'expression' => "SUM({$t}.{$name})",
                    'grain' => $t,
                    'description' => 'Auto-suggested from column type/name',
                ];
                if (count($metrics) >= 8) {
                    break;
                }
            }
        }
        if ($tables !== []) {
            array_unshift($metrics, [
                'id' => 'row_count_' . $tables[0],
                'name' => 'Satır sayısı',
                'expression' => 'COUNT(*)',
                'grain' => $tables[0],
                'description' => 'Auto COUNT(*)',
            ]);
        }
        return $metrics;
    }

    /**
     * @param list<string> $tables
     * @param array<string,list<array<string,mixed>>> $columnsByTable
     */
    private static function inferFilterHints(array $tables, array $columnsByTable): array
    {
        $hints = [];
        $hintNames = ['status', 'state', 'city', 'category', 'type', 'country', 'region'];
        foreach ($tables as $t) {
            foreach ($columnsByTable[$t] ?? [] as $c) {
                $name = (string) $c['name'];
                $low = strtolower($name);
                foreach ($hintNames as $h) {
                    if (str_contains($low, $h)) {
                        $hints[] = [
                            'field' => "{$t}.{$name}",
                            'label' => $name,
                            'example' => '',
                        ];
                        break;
                    }
                }
                if (count($hints) >= 10) {
                    return $hints;
                }
            }
        }
        return $hints;
    }

    /** @param list<string> $tables @return list<string> */
    private static function pickDefaultTables(array $tables): array
    {
        $facts = array_values(array_filter($tables, static fn ($t) => str_starts_with(strtolower($t), 'fact_')));
        $dims = array_values(array_filter($tables, static fn ($t) => str_starts_with(strtolower($t), 'dim_')));
        $picked = array_slice(array_merge($facts, $dims), 0, 6);
        return $picked !== [] ? $picked : array_slice($tables, 0, 6);
    }

    /** @return array<string,mixed> */
    private static function emptyResult(string $reason): array
    {
        return [
            'allowed_tables' => [],
            'joins' => [],
            'metrics' => [],
            'table_descriptions' => [],
            'filter_hints' => [],
            'calendar' => [],
            'default_tables' => [],
            'discovery' => ['source' => 'none', 'error' => $reason],
        ];
    }
}
