<?php

declare(strict_types=1);

namespace App\Tools;

/**
 * LLM payload policy:
 * - Browse / raw listing → UI only (meta to model, zero rows).
 * - Analytics → dynamic meaningful summary sized by result shape (token-safe).
 * - Full series/table for charts stay on the UI path from ReportTool.
 */
final class LlmPayload
{
    /** Control peeks only (probe / execute_query). */
    public const SAMPLE_ROWS = 10;

    /** Analytical table: send all rows to model if ≤ this. */
    public const LLM_FULL_ROWS = 12;

    /** Analytical table: densified sample size when larger. */
    public const LLM_DENSE_ROWS = 8;

    public static function compactToolResult(string $tool, array $result): array
    {
        return match ($tool) {
            'run_report', 'analyze_kpi', 'analyze_breakdown', 'analyze_top_per_group', 'analyze_trend' =>
                (!($result['ok'] ?? true))
                    ? [
                        'ok' => false,
                        'errors' => $result['errors'] ?? ['tool failed'],
                        'retry_hint' => $result['retry_hint'] ?? null,
                    ]
                    : self::analysisResultForLlm($result),
            'list_metrics' => [
                'ok' => $result['ok'] ?? true,
                'metrics' => $result['metrics'] ?? [],
                'dimensions' => $result['dimensions'] ?? [],
                'entities' => $result['entities'] ?? [],
                'note' => $result['note'] ?? null,
            ],
            'search_metrics' => [
                'ok' => $result['ok'] ?? true,
                'dataset_id' => $result['dataset_id'] ?? null,
                'query' => $result['query'] ?? '',
                'metrics' => array_slice($result['metrics'] ?? [], 0, 15),
                'dimensions' => array_slice($result['dimensions'] ?? [], 0, 15),
            ],
            'search_tables' => [
                'ok' => $result['ok'] ?? true,
                'dataset_id' => $result['dataset_id'] ?? null,
                'query' => $result['query'] ?? '',
                'tables' => array_map(static function ($t) {
                    return [
                        'table_id' => $t['table_id'] ?? null,
                        'domain' => $t['domain'] ?? null,
                        'business_entity' => $t['business_entity'] ?? null,
                        'description' => $t['description'] ?? null,
                        'confidence' => $t['confidence'] ?? null,
                        'column_count' => count($t['columns'] ?? []),
                    ];
                }, array_slice($result['tables'] ?? [], 0, 12)),
            ],
            'describe_table' => [
                'ok' => $result['ok'] ?? false,
                'table_id' => $result['table_id'] ?? null,
                'domain' => $result['domain'] ?? null,
                'business_entity' => $result['business_entity'] ?? null,
                'description' => $result['description'] ?? null,
                'candidate_pk' => $result['candidate_pk'] ?? null,
                'columns' => array_slice($result['columns'] ?? [], 0, 40),
                'note' => $result['note'] ?? null,
                'errors' => $result['errors'] ?? null,
            ],
            'find_join_path' => [
                'ok' => $result['ok'] ?? false,
                'tables' => $result['tables'] ?? [],
                'edges' => $result['edges'] ?? [],
                'confidence' => $result['confidence'] ?? null,
                'fan_out_risk' => $result['fan_out_risk'] ?? null,
                'needs_confirmation' => $result['needs_confirmation'] ?? false,
                'ask_user_hint' => $result['ask_user_hint'] ?? null,
                'require_preaggregate' => $result['require_preaggregate'] ?? false,
                'errors' => $result['errors'] ?? null,
            ],
            'register_table_semantics', 'register_join', 'register_canonical_entity' => [
                'ok' => $result['ok'] ?? false,
                'table_id' => $result['table_id'] ?? null,
                'edge_id' => $result['edge_id'] ?? null,
                'entity_type' => $result['entity_type'] ?? null,
                'version' => $result['version'] ?? null,
                'errors' => $result['errors'] ?? null,
            ],
            'describe_column', 'register_metric', 'register_dimension' => [
                'ok' => $result['ok'] ?? false,
                'dataset_id' => $result['dataset_id'] ?? null,
                'metric_id' => $result['metric_id'] ?? null,
                'dimension_id' => $result['dimension_id'] ?? null,
                'version' => $result['version'] ?? null,
                'table' => $result['table'] ?? null,
                'column' => $result['column'] ?? null,
                'type' => $result['type'] ?? null,
                'approx_cardinality' => $result['approx_cardinality'] ?? null,
                'samples' => isset($result['samples']) ? array_slice($result['samples'], 0, 8) : null,
                'suggestion' => $result['suggestion'] ?? null,
                'expression' => $result['expression'] ?? null,
                'expr' => $result['expr'] ?? null,
                'note' => $result['note'] ?? null,
                'errors' => $result['errors'] ?? null,
                'skipped' => $result['skipped'] ?? null,
            ],
            'execute_query' => [
                'ok' => $result['ok'] ?? false,
                'columns' => $result['columns'] ?? [],
                'row_count' => $result['row_count'] ?? 0,
                'truncated' => $result['truncated'] ?? false,
                'execution_mode' => $result['execution_mode'] ?? 'peek',
                'full_data_scan' => false,
                'errors' => $result['errors'] ?? null,
                'warnings' => $result['warnings'] ?? null,
                'delivery' => 'ui_none',
                'note' => 'Peek metadata only. Use analyze_* for full-data analysis.',
            ],
            'list_schema' => self::compactSchemaList($result),
            'probe_join', 'probe_filter' => [
                'ok' => $result['ok'] ?? false,
                'row_count' => $result['row_count'] ?? 0,
                'execution_mode' => 'peek',
                'errors' => $result['errors'] ?? null,
                'warnings' => $result['warnings'] ?? null,
                'sql_used' => isset($result['sql_used'])
                    ? mb_substr((string) $result['sql_used'], 0, 240)
                    : null,
                'note' => 'Probe metadata only.',
            ],
            default => $result,
        };
    }

    /** @param list<array<string,mixed>> $reports */
    public static function compactReportsForPrompt(array $reports): array
    {
        $out = [];
        foreach ($reports as $r) {
            $out[] = self::analysisResultForLlm($r);
        }
        return $out;
    }

    /**
     * Dynamic summary for the model. Never dump browse/raw grids here.
     */
    public static function analysisResultForLlm(array $result): array
    {
        $type = strtolower((string) ($result['report_type'] ?? 'table'));
        $table = $result['table'] ?? [];
        $rows = is_array($table['rows'] ?? null) ? $table['rows'] : [];
        $columns = $table['columns'] ?? [];
        $rowCount = (int) ($result['meta']['row_count'] ?? count($rows));
        $delivery = (string) ($result['delivery'] ?? self::inferDelivery($type, $rowCount));
        $mode = (string) ($result['meta']['execution_mode'] ?? '');
        $fullScan = (bool) ($result['meta']['full_data_scan'] ?? false);

        $base = [
            'ok' => $result['ok'] ?? false,
            'report_id' => $result['report_id'] ?? null,
            'title' => $result['title'] ?? null,
            'report_type' => $type,
            'delivery' => $delivery,
            'meta' => [
                'row_count' => $rowCount,
                'truncated' => (bool) ($result['meta']['truncated'] ?? false),
                'columns' => $columns,
                'execution_mode' => $mode !== '' ? $mode : null,
                'full_data_scan' => $fullScan,
                'warnings' => $result['meta']['warnings'] ?? null,
            ],
            'errors' => $result['errors'] ?? null,
        ];

        // Raw listing / browse: AI sees no rows — UI owns the grid.
        if ($delivery === 'ui_only' || $type === 'browse') {
            return $base + [
                'kpi' => [],
                'note' => 'UI sample grid ready (meta only). Not a full-data dump.',
            ];
        }

        $base['kpi'] = array_slice($result['kpi'] ?? [], 0, 8);

        // Full-data ranking tools: narrate rollups (all entities), not densified head rows.
        if (($result['rollup'] ?? null) !== null && is_array($result['rollup'])) {
            $base['rollup'] = $result['rollup'];
            $base['meta']['partition_by'] = $result['meta']['partition_by'] ?? null;
            $base['meta']['rank_dimension'] = $result['meta']['rank_dimension'] ?? null;
            $base['meta']['entities_ranked'] = $rowCount;
            if (isset($result['presentation_table'])) {
                $ptRows = $result['presentation_table']['rows'] ?? [];
                $shown = min(30, is_array($ptRows) ? count($ptRows) : 0);
                $base['presentation_table'] = [
                    'columns' => $result['presentation_table']['columns'] ?? [],
                    'rows' => array_slice($ptRows, 0, $shown),
                    'label' => $result['presentation_table']['label'] ?? null,
                    'shown' => $shown,
                    'omitted' => max(0, $rowCount - $shown),
                    'and_more' => self::andMoreLabel($rowCount, $shown),
                ];
            }
            $base['note'] = 'FULL-DATA result: narrate from rollup + kpi (covers all '
                . $rowCount . ' entities)'
                . ($rowCount > 30 ? '; ' . self::andMoreLabel($rowCount, 30) : '')
                . '. Do not treat example rows as the whole analysis.';
            if ($rows !== []) {
                $base['examples'] = [
                    'columns' => $columns,
                    'rows' => array_slice($rows, 0, 5),
                ];
            }
            return $base;
        }

        if (!empty($result['presentation_table']['rows']) && ($result['presentation'] ?? '') === 'top_entities') {
            $base['presentation'] = 'top_entities';
            $shown = min(25, count($result['presentation_table']['rows']));
            $total = (int) ($result['meta']['groups_total'] ?? $rowCount);
            $base['presentation_table'] = [
                'columns' => $result['presentation_table']['columns'] ?? $columns,
                'rows' => array_slice($result['presentation_table']['rows'], 0, $shown),
                'shown' => $shown,
                'omitted' => max(0, $total - $shown),
                'and_more' => self::andMoreLabel($total, $shown),
            ];
            $base['meta']['groups_total'] = $total;
            $base['note'] = 'Top entities after full-data aggregation. Narrate ranked rows + kpi'
                . ($total > $shown ? '; ' . self::andMoreLabel($total, $shown) : '') . '.';
            return $base;
        }

        if ($type === 'trend' || ($result['series'] ?? []) !== []) {
            $base['series_summary'] = self::summarizeSeries($result['series'] ?? []);
            $base['note'] = 'Tool summary from warehouse execution. Narrate series_summary + kpi.';
            return $base;
        }

        if ($type === 'kpi') {
            $base['note'] = 'KPI from warehouse tool execution. Narrate these figures only.';
            return $base;
        }

        // Analytical table / distribution / compare — dynamic by size
        $stats = self::compactNumericStats($result['numeric_stats'] ?? []);
        if ($stats !== []) {
            $base['numeric_stats'] = $stats;
        }

        if ($rowCount === 0) {
            $base['note'] = 'Empty result. Explain briefly; suggest calendar/filter retry if relevant.';
            return $base;
        }

        if ($rowCount <= self::LLM_FULL_ROWS) {
            $base['table'] = [
                'columns' => $columns,
                'rows' => array_slice($rows, 0, $rowCount),
                'mode' => 'full',
                'shown' => $rowCount,
                'omitted' => 0,
            ];
            $base['note'] = 'Compact aggregate result from tool.';
            return $base;
        }

        $shown = self::LLM_DENSE_ROWS;
        $base['table'] = [
            'columns' => $columns,
            'rows' => array_slice($rows, 0, $shown),
            'mode' => 'top_n',
            'shown' => $shown,
            'omitted' => max(0, $rowCount - $shown),
            'and_more' => self::andMoreLabel($rowCount, $shown),
            'ui_row_count' => count($rows),
        ];
        $base['note'] = 'top_n tool summary (warehouse already scanned full filtered data). '
            . self::andMoreLabel($rowCount, $shown) . '.';
        return $base;
    }

    private static function andMoreLabel(int $total, int $shown): string
    {
        $left = max(0, $total - $shown);
        if ($left <= 0) {
            return '';
        }
        return "ve {$left} tane daha";
    }

    public static function inferDelivery(string $reportType, int $rowCount): string
    {
        if ($reportType === 'browse') {
            return 'ui_only';
        }
        if (in_array($reportType, ['kpi', 'trend'], true)) {
            return 'summary';
        }
        // Very wide raw-ish tables without aggregates → prefer UI
        if ($reportType === 'table' && $rowCount > 25) {
            return 'ui_only';
        }
        return 'summary';
    }

    /**
     * @param list<array<string,mixed>> $series
     * @return list<array<string,mixed>>
     */
    private static function summarizeSeries(array $series): array
    {
        $out = [];
        foreach (array_slice($series, 0, 3) as $s) {
            $points = $s['points'] ?? [];
            if (!is_array($points) || $points === []) {
                continue;
            }
            $n = count($points);
            $ys = [];
            foreach ($points as $p) {
                if (isset($p['y']) && is_numeric($p['y'])) {
                    $ys[] = (float) $p['y'];
                }
            }
            $first = $points[0];
            $last = $points[array_key_last($points)];
            $firstY = is_numeric($first['y'] ?? null) ? (float) $first['y'] : null;
            $lastY = is_numeric($last['y'] ?? null) ? (float) $last['y'] : null;
            $deltaPct = null;
            if ($firstY !== null && $lastY !== null && abs($firstY) > 1e-9) {
                $deltaPct = round((($lastY - $firstY) / $firstY) * 100, 1);
            }

            $item = [
                'name' => $s['name'] ?? 'series',
                'n' => $n,
                'first' => ['x' => (string) ($first['x'] ?? ''), 'y' => $firstY],
                'last' => ['x' => (string) ($last['x'] ?? ''), 'y' => $lastY],
                'min_y' => $ys !== [] ? min($ys) : null,
                'max_y' => $ys !== [] ? max($ys) : null,
                'delta_pct' => $deltaPct,
            ];

            // Dynamic: short series → full points; long → evenly spaced sample + tail
            if ($n <= 18) {
                $item['points'] = $points;
                $item['mode'] = 'full';
            } else {
                $item['sample'] = self::evenSample($points, 10);
                $item['tail'] = array_slice($points, -3);
                $item['mode'] = 'densified';
            }
            $out[] = $item;
        }
        return $out;
    }

    /** @param list<array<string,mixed>> $points */
    private static function evenSample(array $points, int $k): array
    {
        $n = count($points);
        if ($n <= $k) {
            return $points;
        }
        $out = [];
        for ($i = 0; $i < $k; $i++) {
            $idx = (int) round($i * ($n - 1) / ($k - 1));
            $out[] = $points[$idx];
        }
        return $out;
    }

    /** @param array<string,array<string,float|int>> $stats */
    private static function compactNumericStats(array $stats): array
    {
        $out = [];
        $i = 0;
        foreach ($stats as $col => $s) {
            if ($i++ >= 6) {
                break;
            }
            $out[$col] = [
                'min' => $s['min'] ?? null,
                'max' => $s['max'] ?? null,
                'avg' => isset($s['avg']) ? round((float) $s['avg'], 2) : null,
                'sum' => $s['sum'] ?? null,
            ];
        }
        return $out;
    }

    private static function compactSchemaList(array $result): array
    {
        $tables = [];
        foreach ($result['tables'] ?? [] as $name => $info) {
            $cols = [];
            foreach (array_slice($info['columns'] ?? [], 0, 24) as $c) {
                $cols[] = (string) ($c['name'] ?? '');
            }
            $tables[$name] = [
                'row_count' => $info['row_count'] ?? 0,
                'columns' => $cols,
            ];
        }
        return [
            'ok' => $result['ok'] ?? true,
            'tables' => $tables,
            'note' => 'joins/metrics already in system schema',
        ];
    }

    public static function trimHistoryText(string $text, int $max = 400): string
    {
        $text = trim($text);
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        return mb_substr($text, 0, $max) . '…';
    }
}
