<?php

declare(strict_types=1);

namespace App\Tools;

use PDOException;

final class ReportTool
{
    public function __construct(private readonly SqlGuard $guard)
    {
    }

    public function executeQuery(string $sql, ?int $maxRows = 200): array
    {
        $check = $this->guard->validate($sql);
        if (!$check['ok']) {
            return ['ok' => false, 'errors' => $check['errors'], 'rows' => [], 'columns' => []];
        }
        $limit = $this->guard->clampMaxRows($maxRows);
        try {
            $pdo = Database::pdo();
            $wrapped = $this->guard->wrapWithLimit($check['statement'], $limit);
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
            return [
                'ok' => true,
                'columns' => $columns,
                'rows' => $rows,
                'row_count' => count($rows),
                'truncated' => count($rows) >= $limit,
                'max_rows' => $limit,
                'warnings' => $check['warnings'],
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

        // Browse/raw listings need a higher UI cap; analytics stay modest by default.
        if ($maxRows === null) {
            $maxRows = $reportType === 'browse' ? 100 : 40;
        }

        $result = $this->executeQuery($sql, $maxRows);
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
            'trend' => 24,
            'kpi' => 5,
            default => 40,
        };
        $delivery = $reportType === 'browse' || ($reportType === 'table' && count($rows) > 25)
            ? 'ui_only'
            : 'summary';

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
                'warnings' => $result['warnings'] ?? [],
            ],
        ];
    }
}
