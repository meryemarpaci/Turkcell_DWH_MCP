<?php

declare(strict_types=1);

namespace App\Tools;

use PDOException;

final class ReportTool
{
    public function __construct(private readonly SqlGuard $guard)
    {
    }

    /**
     * @param 'auto'|'peek'|'browse'|'aggregate' $mode
     */
    public function executeQuery(string $sql, ?int $maxRows = null, string $mode = 'auto'): array
    {
        $check = $this->guard->validate($sql);
        if (!$check['ok']) {
            return ['ok' => false, 'errors' => $check['errors'], 'rows' => [], 'columns' => []];
        }

        if ($mode === 'auto') {
            $mode = ($check['is_aggregate'] ?? false) ? 'aggregate' : 'browse';
        }
        $limit = $this->guard->clampMaxRows($maxRows, $mode);
        $prepared = $this->guard->prepareStatement($check['statement'], $mode);
        $statement = $prepared['statement'];
        $prepWarnings = $prepared['warnings'];

        try {
            $pdo = Database::pdo();
            $wrapped = $this->guard->wrapWithLimit($statement, $limit);
            $stmt = $pdo->query($wrapped);
            $rows = $stmt->fetchAll();
            $columns = [];
            if ($rows !== []) {
                $columns = array_keys($rows[0]);
            } else {
                for ($i = 0; $i < $stmt->columnCount(); $i++) {
                    $meta = $stmt->getColumnMeta($i);
                    if ($meta && isset($meta['name'])) {
                        $columns[] = $meta['name'];
                    }
                }
            }
            $truncated = count($rows) >= $limit;
            return [
                'ok' => true,
                'columns' => $columns,
                'rows' => $rows,
                'row_count' => count($rows),
                'truncated' => $truncated,
                'max_rows' => $limit,
                'execution_mode' => $mode,
                // Aggregates compute over the full filtered set; LIMIT only caps returned groups.
                'full_data_scan' => $mode === 'aggregate',
                'stripped_sql_limit' => $prepared['stripped_limit'],
                'warnings' => array_values(array_filter(array_merge(
                    $check['warnings'] ?? [],
                    $prepWarnings
                ))),
            ];
        } catch (PDOException $e) {
            return ['ok' => false, 'errors' => [$e->getMessage()], 'rows' => [], 'columns' => []];
        }
    }

    public function runReport(
        string $sql,
        string $reportType = 'table',
        string $reportId = 'report',
        string $title = 'Report',
        ?int $maxRows = null
    ): array {
        $reportType = strtolower(trim($reportType));
        if ($reportType === '') {
            $reportType = 'table';
        }

        $mode = $this->guard->resolveMode($sql, $reportType, 'report');
        $extraWarnings = [];

        // Tool contract: analysis types require aggregated SQL. Enforcement lives here,
        // not in long prompts — model retries with proper SQL from this error.
        if ($reportType !== 'browse' && $mode !== 'aggregate') {
            return [
                'ok' => false,
                'need_aggregate' => true,
                'errors' => [
                    'run_report analysis requires aggregated SQL '
                    . '(SUM/COUNT/AVG/MIN/MAX and/or GROUP BY). '
                    . 'Raw row SELECT is not full-dataset analysis. '
                    . 'Rewrite with aggregates, or use report_type=browse / execute_query for a small sample.',
                ],
                'report_id' => $reportId,
                'report_type' => $reportType,
                'title' => $title,
            ];
        }

        // Executor owns caps: aggregates always full-data (high group safety cap).
        // Browse uses a small sample cap. max_rows from the model is ignored for aggregates.
        if ($mode === 'aggregate') {
            $cap = FullDataContract::groupCap();
            if ($maxRows !== null && $maxRows < $cap) {
                $extraWarnings[] = "Tool ignored max_rows={$maxRows}; aggregate run_report always scans full filtered data.";
            }
            $maxRows = $cap;
        } elseif ($maxRows === null) {
            $maxRows = match ($mode) {
                'peek' => SqlGuard::PEEK_MAX_ROWS,
                default => SqlGuard::BROWSE_MAX_ROWS,
            };
        }

        $result = $this->executeQuery($sql, $maxRows, $mode);
        if (!$result['ok']) {
            return $result;
        }

        $rows = $result['rows'];
        $columns = $result['columns'];
        $kpi = [];
        $series = [];
        $numericStats = [];

        foreach ($columns as $col) {
            $nums = [];
            foreach ($rows as $r) {
                if (isset($r[$col]) && is_numeric($r[$col])) {
                    $nums[] = (float) $r[$col];
                }
            }
            if ($nums !== []) {
                $numericStats[$col] = [
                    'min' => min($nums),
                    'max' => max($nums),
                    'sum' => array_sum($nums),
                    'avg' => array_sum($nums) / count($nums),
                ];
            }
        }

        if ($reportType === 'kpi' && $rows !== []) {
            foreach ($rows[0] as $k => $v) {
                if (is_numeric($v)) {
                    $kpi[] = ['name' => $k, 'value' => $v + 0, 'unit' => ''];
                }
            }
        }

        if ($reportType === 'trend' && count($columns) >= 2) {
            $xCol = $columns[0];
            $yCols = array_slice($columns, 1);
            foreach ($yCols as $yCol) {
                $points = [];
                foreach ($rows as $r) {
                    if (isset($r[$xCol]) && isset($r[$yCol]) && is_numeric($r[$yCol])) {
                        $points[] = ['x' => (string) $r[$xCol], 'y' => $r[$yCol] + 0];
                    }
                }
                if ($points !== []) {
                    $series[] = ['name' => $yCol, 'points' => $points];
                }
            }
        }

        if ($reportType === 'distribution' && $rows !== []) {
            foreach ($columns as $col) {
                if (isset($numericStats[$col])) {
                    $kpi[] = [
                        'name' => $col . '_avg',
                        'value' => round($numericStats[$col]['avg'], 2),
                        'unit' => '',
                    ];
                }
            }
        }

        if ($kpi === [] && count($rows) === 1) {
            foreach ($rows[0] as $k => $v) {
                if (is_numeric($v)) {
                    $kpi[] = ['name' => $k, 'value' => $v + 0, 'unit' => ''];
                }
            }
        }

        $uiRowCap = match ($reportType) {
            'browse' => 100,
            'trend' => min(120, count($rows)),
            'kpi' => 5,
            'distribution' => 80,
            default => min(80, count($rows)),
        };
        $delivery = $reportType === 'browse' || ($reportType === 'table' && $mode !== 'aggregate' && count($rows) > 25)
            ? 'ui_only'
            : 'summary';

        $warnings = array_values(array_filter(array_merge(
            $result['warnings'] ?? [],
            $extraWarnings
        )));

        return [
            'ok' => true,
            'report_id' => $reportId,
            'report_type' => $reportType,
            'title' => $title,
            'delivery' => $delivery,
            'kpi' => array_slice($kpi, 0, 8),
            'series' => $series,
            'table' => [
                'columns' => $columns,
                'rows' => array_slice($rows, 0, $uiRowCap),
            ],
            'numeric_stats' => $numericStats,
            'meta' => [
                'row_count' => $result['row_count'],
                'truncated' => $result['truncated'],
                'max_rows' => $result['max_rows'] ?? $maxRows,
                'execution_mode' => $mode,
                'full_data_scan' => (bool) ($result['full_data_scan'] ?? false),
                'stripped_sql_limit' => $result['stripped_sql_limit'] ?? null,
                'warnings' => $warnings,
            ],
        ];
    }
}
