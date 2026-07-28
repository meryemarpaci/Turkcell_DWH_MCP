<?php

declare(strict_types=1);

namespace App\Tools;

use App\SemanticConfig;

final class SchemaTool
{
    public function listSchema(?array $tables = null): array
    {
        $pdo = Database::pdo();
        $allowed = SemanticConfig::allowedTables();
        $descriptions = SemanticConfig::all()['table_descriptions'] ?? [];
        $target = $tables ? array_values(array_intersect($tables, $allowed)) : $allowed;

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

        return [
            'ok' => true,
            'tables' => $out,
            'joins' => SemanticConfig::joins(),
            'metrics' => SemanticConfig::metrics(),
            'filter_hints' => SemanticConfig::all()['filter_hints'] ?? [],
        ];
    }

    public function schemaPromptBlock(bool $compact = true): string
    {
        $schema = $this->listSchema();
        $lines = ['# DWH SCHEMA (metadata only — never dump table rows here)', ''];
        foreach ($schema['tables'] as $table => $info) {
            $colNames = array_map(static fn (array $c): string => $c['name'], $info['columns']);
            $lines[] = "## {$table} (~{$info['row_count']} rows)";
            if (!$compact && ($info['description'] ?? '') !== '') {
                $lines[] = $info['description'];
            }
            $lines[] = 'cols: ' . implode(', ', $colNames);
            $lines[] = '';
        }
        $lines[] = '# ALLOWED JOINS (edge ids for probe_join)';
        foreach ($schema['joins'] as $j) {
            $lines[] = "- {$j['id']}: {$j['left_table']}.{$j['left_key']}={$j['right_table']}.{$j['right_key']}";
        }
        $lines[] = '';
        $lines[] = '# METRICS';
        foreach ($schema['metrics'] as $m) {
            $lines[] = "- {$m['id']}: {$m['expression']} ({$m['grain']})";
        }
        $lines[] = '';
        $lines[] = '# FILTER HINTS';
        foreach ($schema['filter_hints'] as $h) {
            $lines[] = "- {$h['field']}: {$h['label']} e.g. {$h['example']}";
        }
        return implode("\n", $lines);
    }

    public function proposeTables(string $intentHint): array
    {
        $hint = mb_strtolower($intentHint);
        $scores = [];
        foreach (SemanticConfig::allowedTables() as $t) {
            $scores[$t] = 0;
        }
        $map = [
            'customer|müşteri|eyalet|state|şehir|city|yeni|eski' => ['dim_customer', 'fact_orders'],
            'product|ürün|kategori|category|telefon|renk|model' => ['dim_product', 'fact_order_items'],
            'seller|satıcı' => ['dim_seller', 'fact_order_items'],
            'geo|konum|zip|lat' => ['dim_geolocation', 'dim_customer'],
            'order|sipariş|trend|satış|gmv|ciro' => ['fact_orders', 'fact_order_items'],
            'payment|ödeme' => ['fact_order_payments', 'fact_orders'],
            'review|yorum|skor' => ['fact_order_reviews', 'fact_orders'],
            'kampanya|campaign' => ['fact_orders', 'fact_order_items'],
        ];
        foreach ($map as $pattern => $tables) {
            if (preg_match('/' . $pattern . '/u', $hint)) {
                foreach ($tables as $t) {
                    $scores[$t] = ($scores[$t] ?? 0) + 2;
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
            $picked = ['fact_orders', 'fact_order_items', 'dim_customer', 'dim_product'];
        }
        return ['ok' => true, 'tables' => array_values(array_unique($picked)), 'hint' => $intentHint];
    }
}
