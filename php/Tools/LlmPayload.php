<?php

declare(strict_types=1);

namespace App\Tools;

/**
 * Keeps LLM context small: never ship full query dumps to the model.
 * UI may receive richer report payloads separately.
 */
final class LlmPayload
{
    public const SAMPLE_ROWS = 5;

    public static function compactToolResult(string $tool, array $result): array
    {
        return match ($tool) {
            'run_report' => self::compactReport($result),
            'execute_query' => [
                'ok' => $result['ok'] ?? false,
                'columns' => $result['columns'] ?? [],
                'rows' => array_slice($result['rows'] ?? [], 0, self::SAMPLE_ROWS),
                'row_count' => $result['row_count'] ?? 0,
                'truncated' => ($result['truncated'] ?? false)
                    || (($result['row_count'] ?? 0) > self::SAMPLE_ROWS),
                'errors' => $result['errors'] ?? null,
                'warnings' => $result['warnings'] ?? null,
                'note' => 'Only first ' . self::SAMPLE_ROWS . ' rows sent to LLM',
            ],
            'list_schema' => self::compactSchemaList($result),
            'probe_join', 'probe_filter' => [
                'ok' => $result['ok'] ?? false,
                'row_count' => $result['row_count'] ?? 0,
                'sample' => array_slice($result['sample'] ?? [], 0, self::SAMPLE_ROWS),
                'errors' => $result['errors'] ?? null,
                'warnings' => $result['warnings'] ?? null,
                'sql_used' => isset($result['sql_used'])
                    ? mb_substr((string) $result['sql_used'], 0, 300)
                    : null,
            ],
            default => $result,
        };
    }

    /** @param list<array<string,mixed>> $reports */
    public static function compactReportsForPrompt(array $reports): array
    {
        $out = [];
        foreach ($reports as $r) {
            $out[] = self::compactReport($r);
        }
        return $out;
    }

    public static function compactReport(array $result): array
    {
        $table = $result['table'] ?? [];
        $series = [];
        foreach ($result['series'] ?? [] as $s) {
            $series[] = [
                'name' => $s['name'] ?? '',
                'points' => array_slice($s['points'] ?? [], 0, 12),
            ];
        }
        return [
            'ok' => $result['ok'] ?? false,
            'report_id' => $result['report_id'] ?? null,
            'title' => $result['title'] ?? null,
            'report_type' => $result['report_type'] ?? null,
            'kpi' => $result['kpi'] ?? [],
            'series' => $series,
            'table' => [
                'columns' => $table['columns'] ?? [],
                'rows' => array_slice($table['rows'] ?? [], 0, self::SAMPLE_ROWS),
            ],
            'numeric_stats' => $result['numeric_stats'] ?? [],
            'meta' => [
                'row_count' => $result['meta']['row_count'] ?? count($table['rows'] ?? []),
                'truncated' => true,
                'llm_sample_rows' => self::SAMPLE_ROWS,
            ],
            'errors' => $result['errors'] ?? null,
            'note' => 'Aggregates/KPI from SQL; sample rows only (max ' . self::SAMPLE_ROWS . ')',
        ];
    }

    private static function compactSchemaList(array $result): array
    {
        $tables = [];
        foreach ($result['tables'] ?? [] as $name => $info) {
            $cols = [];
            foreach ($info['columns'] ?? [] as $c) {
                $cols[] = ($c['name'] ?? '') . ':' . ($c['type'] ?? '');
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
