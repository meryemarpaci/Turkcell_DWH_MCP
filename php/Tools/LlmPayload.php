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
    public const SAMPLE_ROWS = 5;

    /** Analytical table: send all rows to model if ≤ this. */
    public const LLM_FULL_ROWS = 12;

    /** Analytical table: densified sample size when larger. */
    public const LLM_DENSE_ROWS = 8;

    public static function compactToolResult(string $tool, array $result): array
    {
        return match ($tool) {
            'run_report' => self::analysisResultForLlm($result),
            'execute_query' => [
                'ok' => $result['ok'] ?? false,
                'columns' => $result['columns'] ?? [],
                'row_count' => $result['row_count'] ?? 0,
                'truncated' => $result['truncated'] ?? false,
                'errors' => $result['errors'] ?? null,
                'warnings' => $result['warnings'] ?? null,
                'delivery' => 'ui_none',
                'note' => 'Control peek metadata only — raw rows stay in tool logs/UI path. Prefer run_report.',
            ],
            'list_schema' => self::compactSchemaList($result),
            'probe_join', 'probe_filter' => [
                'ok' => $result['ok'] ?? false,
                'row_count' => $result['row_count'] ?? 0,
                'errors' => $result['errors'] ?? null,
                'warnings' => $result['warnings'] ?? null,
                'sql_used' => isset($result['sql_used'])
                    ? mb_substr((string) $result['sql_used'], 0, 240)
                    : null,
                'note' => 'Probe: COUNT only for the model (sample not forwarded).',
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
            ],
            'errors' => $result['errors'] ?? null,
        ];

        // Raw listing / browse: AI sees no rows — UI owns the grid.
        if ($delivery === 'ui_only' || $type === 'browse') {
            return $base + [
                'kpi' => [],
                'note' => 'Full result is shown only in the UI table. '
                    . 'Tell the user the grid is ready (row_count/columns). Do not invent row values.',
            ];
        }

        $base['kpi'] = array_slice($result['kpi'] ?? [], 0, 8);

        if ($type === 'trend' || ($result['series'] ?? []) !== []) {
            $base['series_summary'] = self::summarizeSeries($result['series'] ?? []);
            $base['note'] = 'Chart is in UI. Narrate from series_summary + kpi only.';
            return $base;
        }

        if ($type === 'kpi') {
            $base['note'] = 'Snapshot KPIs from PHP. Narrate these figures only.';
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
            // Small analytical result: send all rows — meaningful, still tiny.
            $base['table'] = [
                'columns' => $columns,
                'rows' => array_slice($rows, 0, $rowCount),
                'mode' => 'full',
            ];
            $base['note'] = 'Small analytical result — full rows included. Narrate accurately.';
            return $base;
        }

        // Larger analytical result: densify (head + tail + stats), not a misleading tiny peek.
        $base['table'] = [
            'columns' => $columns,
            'rows_head' => array_slice($rows, 0, self::LLM_DENSE_ROWS),
            'rows_tail' => array_slice($rows, -2),
            'mode' => 'densified',
            'ui_row_count' => count($rows),
        ];
        $base['note'] = 'Densified analytical summary. Full grid may also be in UI. '
            . 'Do not invent middle rows; use stats + head/tail.';
        return $base;
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
