<?php

declare(strict_types=1);

namespace App\Tools;

use App\DatasetProfile;
use App\Discovery\QueryPlanner;
use App\Semantic\RegistryService;
use App\SemanticConfig;

/**
 * Structured full-data analytics tools.
 * The LLM passes metrics/filters/dimensions — this class builds SQL, scans the
 * full filtered warehouse, and returns compact report payloads (not raw facts).
 */
final class AnalyticsTool
{
    private RegistryService $registry;
    private QueryPlanner $planner;

    public function __construct(
        private readonly ReportTool $report,
        ?RegistryService $registry = null,
        ?QueryPlanner $planner = null
    ) {
        FullDataContract::prepareRuntime();
        $this->registry = $registry ?? new RegistryService();
        $this->registry->ensureSeededFromProfile();
        $this->planner = $planner ?? new QueryPlanner();
    }

    /** @return list<array{id:string,name:string,description:string}> */
    public function listMetrics(): array
    {
        $search = $this->registry->search('', 50);
        $out = [];
        foreach ($search['metrics'] as $m) {
            $out[] = [
                'id' => (string) ($m['metric_id'] ?? ''),
                'name' => (string) ($m['metric_id'] ?? ''),
                'description' => (string) ($m['description'] ?? ''),
            ];
        }
        return $out;
    }

    /** @return list<string> */
    public function listDimensions(): array
    {
        $search = $this->registry->search('', 50);
        return array_values(array_map(
            static fn ($d) => (string) ($d['dimension_id'] ?? ''),
            $search['dimensions']
        ));
    }

    /** @return list<string> */
    public function listEntities(): array
    {
        $search = $this->registry->search('', 80);
        $out = [];
        foreach ($search['dimensions'] as $d) {
            if (!empty($d['entity'])) {
                $out[] = (string) $d['dimension_id'];
            }
        }
        return $out !== [] ? $out : $this->listDimensions();
    }

    public function registry(): RegistryService
    {
        return $this->registry;
    }

    /**
     * Full-data KPI snapshot (one row of aggregates).
     *
     * @param list<string> $metrics
     * @param array<string,scalar|null> $filters
     */
    public function analyzeKpi(
        array $metrics = [],
        ?string $dateFrom = null,
        ?string $dateTo = null,
        array $filters = [],
        bool $applyDefaultStatus = true,
        string $title = 'KPI'
    ): array {
        $built = $this->buildSql($metrics, [], null, $dateFrom, $dateTo, $filters, $applyDefaultStatus, null);
        if (!$built['ok']) {
            return $built;
        }
        $result = $this->report->runReport($built['sql'], 'kpi', 'analyze_kpi', $title, null);
        return $this->attachMeta($result, $built, 'analyze_kpi');
    }

    /**
     * Full-data breakdown by dimension(s). Aggregation scans all matching rows.
     * Optional top_n only trims returned groups AFTER full aggregation.
     *
     * @param list<string> $metrics
     * @param list<string>|string $dimensions
     * @param array<string,scalar|null> $filters
     */
    public function analyzeBreakdown(
        array $metrics = [],
        array|string $dimensions = [],
        ?string $dateFrom = null,
        ?string $dateTo = null,
        array $filters = [],
        bool $applyDefaultStatus = true,
        ?int $topN = null,
        string $title = 'Breakdown'
    ): array {
        $dims = is_array($dimensions) ? $dimensions : [$dimensions];
        $dims = array_values(array_filter(array_map('strval', $dims), static fn ($d) => $d !== ''));
        if ($dims === []) {
            return ['ok' => false, 'errors' => ['dimensions required (e.g. customer_state, category)']];
        }
        $built = $this->buildSql($metrics, $dims, null, $dateFrom, $dateTo, $filters, $applyDefaultStatus, $topN);
        if (!$built['ok']) {
            return $built;
        }
        $result = $this->report->runReport($built['sql'], 'table', 'analyze_breakdown', $title, null);
        $result = $this->attachMeta($result, $built, 'analyze_breakdown');
        if (!($result['ok'] ?? false)) {
            return $result;
        }

        // Strategic UI: low-cardinality → full table; high-cardinality IDs → top rows only + bar if 1 dim
        $rows = $result['table']['rows'] ?? [];
        $cols = $result['table']['columns'] ?? [];
        $n = (int) ($result['meta']['row_count'] ?? count($rows));
        $isEntityDim = count($dims) === 1 && str_ends_with(strtolower($dims[0]), '_id');
        if ($isEntityDim && $topN === null && $n > 25) {
            // Auto top-25 for entity lists so UI/LLM stay useful (full scan already done for ordering)
            $rows = array_slice($rows, 0, 25);
            $result['table']['rows'] = $rows;
            $result['meta']['ui_top_n'] = 25;
            $result['meta']['groups_total'] = $n;
            $result['presentation'] = 'top_entities';
        } elseif ($n <= 40) {
            $result['presentation'] = 'table';
        } else {
            $result['presentation'] = 'table_sample';
        }

        // Bar chart when single dimension + numeric metric
        if (count($dims) === 1 && count($cols) >= 2) {
            $xCol = $cols[0];
            $yCol = null;
            foreach ($cols as $c) {
                if ($c === $xCol) {
                    continue;
                }
                if (isset($rows[0][$c]) && is_numeric($rows[0][$c])) {
                    $yCol = $c;
                    break;
                }
            }
            if ($yCol !== null) {
                $points = [];
                foreach (array_slice($rows, 0, 24) as $r) {
                    if (isset($r[$xCol], $r[$yCol]) && is_numeric($r[$yCol])) {
                        $points[] = ['x' => (string) $r[$xCol], 'y' => $r[$yCol] + 0];
                    }
                }
                if ($points !== []) {
                    $result['series'] = [['name' => $yCol, 'points' => $points]];
                    $result['report_type'] = 'trend'; // reuse chart renderer as bar-capable via chat.js
                    $result['chart_kind'] = 'bar';
                }
            }
        }

        $result['presentation_table'] = [
            'columns' => $cols,
            'rows' => $rows,
            'label' => $title,
        ];
        if (!empty($built['fanout_warning'])) {
            $result['meta']['warnings'] = array_values(array_filter(array_merge(
                $result['meta']['warnings'] ?? [],
                [$built['fanout_warning']]
            )));
        }
        return $result;
    }

    /**
     * Full-data "top N within each group" analysis.
     * Example: every seller's best category, with seller_state kept for breakdown.
     * Scans the entire filtered warehouse; ranking happens after full aggregation.
     *
     * @param list<string> $metrics
     * @param list<string> $extraDimensions
     * @param array<string,scalar|null> $filters
     */
    public function analyzeTopPerGroup(
        string $partitionBy,
        string $rankDimension,
        array $metrics = [],
        array $extraDimensions = [],
        int $topNPerGroup = 1,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        array $filters = [],
        bool $applyDefaultStatus = true,
        string $title = 'Top per group'
    ): array {
        $topNPerGroup = max(1, min(20, $topNPerGroup));
        $part = $this->resolveField($partitionBy);
        $rank = $this->resolveField($rankDimension);
        if ($part === null) {
            return [
                'ok' => false,
                'errors' => [
                    "Unknown partition_by '{$partitionBy}'. Allowed registry dimensions — use search_metrics.",
                ],
                'retry_hint' => 'search_metrics then use a dimension_id (e.g. seller_id).',
            ];
        }
        // rank_dimension must be an attribute, not a metric
        if ($this->registry->resolveMetric($rankDimension) !== null
            && $this->registry->resolveDimension($rankDimension) === null
        ) {
            return [
                'ok' => false,
                'errors' => [
                    "rank_dimension '{$rankDimension}' is a metric, not a dimension. "
                    . 'For top entities by a metric use analyze_breakdown + top_n. '
                    . 'For each-entity top attribute use a dimension_id as rank_dimension.',
                ],
                'retry_hint' => 'analyze_breakdown dimensions=["seller_id"] metrics=["gmv"] top_n=10',
            ];
        }
        if ($rank === null) {
            return [
                'ok' => false,
                'errors' => [
                    "Unknown rank_dimension '{$rankDimension}'. Use search_metrics for dimension ids.",
                ],
            ];
        }
        if ($part['alias'] === $rank['alias']) {
            return [
                'ok' => false,
                'errors' => [
                    "partition_by and rank_dimension resolve to the same field '{$part['alias']}'. "
                    . 'analyze_top_per_group needs two different dimensions '
                    . '(e.g. partition_by=seller_id, rank_dimension=category).',
                ],
                'retry_hint' => 'For "filter by X, break down by Y" use analyze_breakdown '
                    . 'with filters + dimensions (not top_per_group). '
                    . 'Example: filters=[{"field":"seller_city","value":"sao paulo"}], '
                    . 'dimensions=["customer_state"], metrics=["avg_delivery_days","order_count"].',
            ];
        }

        $metricDefs = $this->resolveMetrics($metrics);
        if ($metricDefs === []) {
            return ['ok' => false, 'errors' => ['No valid metrics']];
        }
        $split = $this->splitMetricsForFanOut($metricDefs);
        $metricDefs = $split['keep'];
        $fanoutWarning = $split['warning'];
        if ($metricDefs === []) {
            return [
                'ok' => false,
                'errors' => [$fanoutWarning ?? 'Incompatible metrics'],
                'retry_hint' => 'Call analyze_* once per metric grain (items vs payments vs reviews).',
            ];
        }

        $tables = [];
        $joinIds = [];
        foreach ([$part, $rank] as $field) {
            foreach ($field['tables'] as $t) {
                $tables[$t] = true;
            }
            foreach ($field['joins'] as $j) {
                $joinIds[$j] = true;
            }
        }
        foreach ($metricDefs as $m) {
            foreach ($this->tablesForMetric($m) as $t) {
                $tables[$t] = true;
            }
            foreach ($this->joinsForMetric($m) as $j) {
                $joinIds[$j] = true;
            }
        }

        $extra = [];
        foreach ($extraDimensions as $d) {
            $f = $this->resolveField((string) $d);
            if ($f === null) {
                return ['ok' => false, 'errors' => ["Unknown extra dimension '{$d}'"]];
            }
            if ($f['alias'] === $part['alias'] || $f['alias'] === $rank['alias']) {
                continue;
            }
            $extra[$f['alias']] = $f;
            foreach ($f['tables'] as $t) {
                $tables[$t] = true;
            }
            foreach ($f['joins'] as $j) {
                $joinIds[$j] = true;
            }
        }

        $where = [];
        $filterInfo = [];
        $grainTable = $this->grainTableForMetrics($metricDefs);
        foreach ($filters as $field => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $f = $this->resolveField((string) $field);
            if ($f === null) {
                return ['ok' => false, 'errors' => ["Unknown filter '{$field}'"]];
            }
            $filterInfo[$f['alias']] = $value;
            if ($grainTable !== '' && $this->pathFansOutFrom($grainTable, $f['tables'])) {
                $exists = $this->buildExistsSemiJoin($grainTable, $f, $value);
                if ($exists === null) {
                    return [
                        'ok' => false,
                        'errors' => ["Filter '{$field}' fan-out from {$grainTable}; no safe semi-join."],
                    ];
                }
                $where[] = $exists;
                continue;
            }
            foreach ($f['tables'] as $t) {
                $tables[$t] = true;
            }
            foreach ($f['joins'] as $j) {
                $joinIds[$j] = true;
            }
            $where[] = "{$f['expr']} = " . $this->sqlLiteral($value);
        }

        $cal = DatasetProfile::calendar();
        $dateCol = (string) ($cal['date_column'] ?? 'order_purchase_timestamp');
        $fact = (string) ($cal['fact_table'] ?? 'fact_orders');
        $dateExpr = str_contains($dateCol, '.') ? $dateCol : "{$fact}.{$dateCol}";
        $tables[$fact] = true;
        if (
            isset($tables['fact_order_items'])
            || isset($tables['fact_order_payments'])
            || isset($tables['fact_order_reviews'])
            || isset($tables['dim_customer'])
            || isset($tables['dim_seller'])
            || isset($tables['dim_product'])
        ) {
            $tables['fact_orders'] = true;
        }

        if ($dateFrom !== null && $dateFrom !== '') {
            $where[] = "{$dateExpr} >= " . $this->sqlLiteral($dateFrom);
        }
        if ($dateTo !== null && $dateTo !== '') {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
                $where[] = "{$dateExpr} < " . $this->sqlLiteral($dateTo . ' 23:59:59.999');
            } else {
                $where[] = "{$dateExpr} <= " . $this->sqlLiteral($dateTo);
            }
        }
        if ($applyDefaultStatus) {
            $status = trim((string) (DatasetProfile::defaults()['status_filter_sql'] ?? ''));
            if ($status !== '') {
                $where[] = $status;
            }
        }

        $from = $this->buildFromClause(array_keys($tables), array_keys($joinIds));
        if (!$from['ok']) {
            return $from;
        }

        $selectInner = [
            "{$part['expr']} AS {$part['alias']}",
            "{$rank['expr']} AS {$rank['alias']}",
        ];
        $groupInner = [$part['expr'], $rank['expr']];
        foreach ($extra as $alias => $f) {
            $selectInner[] = "{$f['expr']} AS {$alias}";
            $groupInner[] = $f['expr'];
        }
        $firstMetric = null;
        foreach ($metricDefs as $m) {
            $alias = preg_replace('/[^a-z0-9_]/i', '_', (string) $m['id']) ?: 'metric';
            $selectInner[] = "{$m['expression']} AS {$alias}";
            if ($firstMetric === null) {
                $firstMetric = $alias;
            }
        }

        $whereSql = $where !== [] ? ("\nWHERE " . implode("\n  AND ", $where)) : '';
        $sql = "WITH grouped AS (\n"
            . '  SELECT ' . implode(",\n         ", $selectInner) . "\n"
            . "  FROM {$from['sql']}"
            . $whereSql . "\n"
            . '  GROUP BY ' . implode(', ', $groupInner) . "\n"
            . "), ranked AS (\n"
            . "  SELECT *,\n"
            . "         ROW_NUMBER() OVER (PARTITION BY {$part['alias']} ORDER BY {$firstMetric} DESC) AS rn\n"
            . "  FROM grouped\n"
            . ")\n"
            . "SELECT * FROM ranked\n"
            . "WHERE rn <= {$topNPerGroup}\n"
            . "ORDER BY {$firstMetric} DESC";

        $built = [
            'ok' => true,
            'sql' => $sql,
            'metrics' => array_map(static fn ($m) => $m['id'], $metricDefs),
            'dimensions' => array_values(array_unique(array_merge(
                [$part['alias'], $rank['alias']],
                array_keys($extra)
            ))),
            'filters' => $filterInfo,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'time_grain' => null,
            'top_n' => null,
            'partition_by' => $part['alias'],
            'rank_dimension' => $rank['alias'],
            'top_n_per_group' => $topNPerGroup,
            'full_data_scan' => true,
        ];

        // Execute full ranked result (all entities), then summarize for the LLM.
        // Important: do not rely on ReportTool UI slice (80 rows) — that made answers look "limited".
        $exec = $this->report->executeQuery($sql, SqlGuard::AGGREGATE_MAX_ROWS, 'aggregate');
        if (!($exec['ok'] ?? false)) {
            $exec['tool'] = 'analyze_top_per_group';
            $exec['sql_used'] = $sql;
            return $exec;
        }

        /** @var list<array<string,mixed>> $allRows */
        $allRows = $exec['rows'] ?? [];
        $columns = $exec['columns'] ?? [];
        $metricIds = array_map(static fn ($m) => (string) $m['id'], $metricDefs);
        $rollup = $this->buildTopPerGroupRollup(
            $allRows,
            $part['alias'],
            $rank['alias'],
            array_keys($extra),
            $metricIds
        );

        $kpi = [
            ['name' => 'entity_count', 'value' => count($allRows), 'unit' => 'sellers/groups'],
            ['name' => 'full_data_scan', 'value' => 1, 'unit' => ''],
        ];
        if (isset($rollup['totals']['gmv'])) {
            $kpi[] = ['name' => 'top_line_gmv_sum', 'value' => $rollup['totals']['gmv'], 'unit' => ''];
        }
        if (isset($rollup['totals']['order_count'])) {
            $kpi[] = ['name' => 'top_line_orders_sum', 'value' => $rollup['totals']['order_count'], 'unit' => ''];
        }
        if (isset($rollup['by_state'])) {
            $kpi[] = ['name' => 'state_count', 'value' => count($rollup['by_state']), 'unit' => ''];
        }

        $result = [
            'ok' => true,
            'report_id' => 'analyze_top_per_group',
            'report_type' => 'table',
            'title' => $title,
            'delivery' => 'summary',
            'kpi' => $kpi,
            'series' => [],
            'rollup' => $rollup,
            'table' => [
                'columns' => $columns,
                // UI sample only — full coverage is in rollup/kpi
                'rows' => array_slice($allRows, 0, 100),
            ],
            'numeric_stats' => [],
            'meta' => [
                'row_count' => count($allRows),
                'truncated' => (bool) ($exec['truncated'] ?? false),
                'max_rows' => $exec['max_rows'] ?? null,
                'execution_mode' => 'aggregate',
                'full_data_scan' => true,
                'warnings' => array_values(array_filter(array_merge(
                    $exec['warnings'] ?? [],
                    $fanoutWarning !== null ? [$fanoutWarning] : []
                ))),
            ],
            'presentation' => 'rollup',
            'presentation_table' => $this->rollupAsTable($rollup),
        ];

        return $this->attachMeta($result, $built, 'analyze_top_per_group');
    }

    /** @param array<string,mixed> $rollup */
    private function rollupAsTable(array $rollup): ?array
    {
        $byState = $rollup['by_state'] ?? null;
        if (is_array($byState) && $byState !== []) {
            return [
                'columns' => array_keys($byState[0]),
                'rows' => $byState,
                'label' => 'Eyalet / state rollup (tüm entity\'ler)',
            ];
        }
        foreach ($rollup as $key => $val) {
            if (is_string($key) && str_starts_with($key, 'top_') && is_array($val) && $val !== []) {
                return [
                    'columns' => array_keys($val[0]),
                    'rows' => array_slice($val, 0, 20),
                    'label' => 'Top rank values across all entities',
                ];
            }
        }
        return null;
    }

    /**
     * Build LLM-facing rollups over ALL per-entity top rows (not a densified peek).
     *
     * @param list<array<string,mixed>> $rows
     * @param list<string> $extraAliases
     * @param list<string> $metricIds
     * @return array<string,mixed>
     */
    private function buildTopPerGroupRollup(
        array $rows,
        string $partitionAlias,
        string $rankAlias,
        array $extraAliases,
        array $metricIds
    ): array {
        $stateKey = null;
        foreach (['seller_state', 'customer_state'] as $cand) {
            if (in_array($cand, $extraAliases, true) || ($rows[0][$cand] ?? null) !== null) {
                $stateKey = $cand;
                break;
            }
        }
        // Also detect from row keys
        if ($stateKey === null && $rows !== []) {
            foreach (array_keys($rows[0]) as $k) {
                if (str_ends_with((string) $k, '_state')) {
                    $stateKey = (string) $k;
                    break;
                }
            }
        }

        $primaryMetric = $metricIds[0] ?? 'gmv';
        $totals = [];
        foreach ($metricIds as $mid) {
            $sum = 0.0;
            $any = false;
            foreach ($rows as $r) {
                if (isset($r[$mid]) && is_numeric($r[$mid])) {
                    $sum += (float) $r[$mid];
                    $any = true;
                }
            }
            if ($any) {
                $totals[$mid] = round($sum, 2);
            }
        }

        // How often each rank_dimension value is an entity's #1
        $rankFreq = [];
        $rankMetric = [];
        foreach ($rows as $r) {
            $label = (string) ($r[$rankAlias] ?? '(null)');
            $rankFreq[$label] = ($rankFreq[$label] ?? 0) + 1;
            if (isset($r[$primaryMetric]) && is_numeric($r[$primaryMetric])) {
                $rankMetric[$label] = ($rankMetric[$label] ?? 0) + (float) $r[$primaryMetric];
            }
        }
        arsort($rankFreq);
        $topRankValues = [];
        foreach (array_slice($rankFreq, 0, 15, true) as $label => $cnt) {
            $topRankValues[] = [
                $rankAlias => $label,
                'entity_count' => $cnt,
                'share_pct' => $rows !== [] ? round(100.0 * $cnt / count($rows), 1) : 0,
                $primaryMetric => isset($rankMetric[$label]) ? round($rankMetric[$label], 2) : null,
            ];
        }

        $byState = [];
        if ($stateKey !== null) {
            $stateBag = [];
            $stateMetric = [];
            $stateTopCat = []; // state => [category => count]
            foreach ($rows as $r) {
                $st = (string) ($r[$stateKey] ?? '(null)');
                $stateBag[$st] = ($stateBag[$st] ?? 0) + 1;
                if (isset($r[$primaryMetric]) && is_numeric($r[$primaryMetric])) {
                    $stateMetric[$st] = ($stateMetric[$st] ?? 0) + (float) $r[$primaryMetric];
                }
                $cat = (string) ($r[$rankAlias] ?? '(null)');
                $stateTopCat[$st][$cat] = ($stateTopCat[$st][$cat] ?? 0) + 1;
            }
            arsort($stateMetric);
            foreach ($stateMetric as $st => $metricSum) {
                $cats = $stateTopCat[$st] ?? [];
                arsort($cats);
                $topCat = array_key_first($cats);
                $byState[] = [
                    $stateKey => $st,
                    'entity_count' => $stateBag[$st] ?? 0,
                    $primaryMetric => round((float) $metricSum, 2),
                    'most_common_top_' . $rankAlias => $topCat,
                    'most_common_top_count' => $topCat !== null ? ($cats[$topCat] ?? 0) : 0,
                ];
            }
            $byState = array_slice($byState, 0, 30);
        }

        return [
            'coverage' => [
                'entities_ranked' => count($rows),
                'partition_by' => $partitionAlias,
                'rank_dimension' => $rankAlias,
                'note' => 'Covers every entity in the filtered warehouse (not a sample).',
            ],
            'totals' => $totals,
            'top_' . $rankAlias . '_as_number_one' => $topRankValues,
            'by_state' => $byState,
        ];
    }

    /**
     * Full-data time series.
     *
     * @param list<string> $metrics
     * @param array<string,scalar|null> $filters
     */
    public function analyzeTrend(
        array $metrics = [],
        string $grain = 'month',
        ?string $dateFrom = null,
        ?string $dateTo = null,
        array $filters = [],
        bool $applyDefaultStatus = true,
        string $title = 'Trend'
    ): array {
        $grain = strtolower(trim($grain));
        if (!in_array($grain, ['day', 'month', 'year'], true)) {
            return ['ok' => false, 'errors' => ['grain must be day|month|year']];
        }
        $built = $this->buildSql($metrics, [], $grain, $dateFrom, $dateTo, $filters, $applyDefaultStatus, null);
        if (!$built['ok']) {
            return $built;
        }
        $result = $this->report->runReport($built['sql'], 'trend', 'analyze_trend', $title, null);
        return $this->attachMeta($result, $built, 'analyze_trend');
    }

    /**
     * Resolve entity or dimension field from semantic registry (id → expr).
     *
     * @return array{expr:string,alias:string,tables:list<string>,joins:list<string>}|null
     */
    private function resolveField(string $name): ?array
    {
        $row = $this->registry->resolveDimension($name);
        if ($row === null) {
            return null;
        }
        return [
            'expr' => (string) ($row['expr'] ?? ''),
            'alias' => (string) ($row['alias'] ?? $row['dimension_id'] ?? $name),
            'tables' => array_values(array_map('strval', $row['tables'] ?? [])),
            'joins' => array_values(array_map('strval', $row['joins'] ?? [])),
        ];
    }

    /**
     * Keep compatible metrics; drop fan-out mix with a warning (retry remaining grain separately).
     *
     * @param list<array<string,mixed>> $metrics
     * @return array{keep:list<array<string,mixed>>,warning:?string}
     */
    private function splitMetricsForFanOut(array $metrics): array
    {
        if ($metrics === []) {
            return ['keep' => [], 'warning' => null];
        }
        $priority = ['order_item' => 1, 'order' => 2, 'payment' => 3, 'review' => 4];
        $bestGrain = null;
        $bestPri = 999;
        foreach ($metrics as $m) {
            $g = (string) ($m['grain'] ?? 'order');
            $p = $priority[$g] ?? 50;
            if ($p < $bestPri) {
                $bestPri = $p;
                $bestGrain = $g;
            }
        }
        $keep = [];
        $dropped = [];
        foreach ($metrics as $m) {
            $g = (string) ($m['grain'] ?? 'order');
            // order + order_item OK together; payment/review separate
            $compatible = ($g === $bestGrain)
                || ($bestGrain === 'order_item' && $g === 'order')
                || ($bestGrain === 'order' && $g === 'order_item');
            if ($compatible) {
                $keep[] = $m;
            } else {
                $dropped[] = (string) ($m['id'] ?? $g);
            }
        }
        $warning = null;
        if ($dropped !== []) {
            $warning = 'Dropped incompatible metrics to avoid fan-out: '
                . implode(', ', $dropped)
                . '. Call analyze_* again for those metrics separately.';
        }
        return ['keep' => $keep, 'warning' => $warning];
    }

    /** @param array<string,mixed> $m @return list<string> */
    private function tablesForMetric(array $m): array
    {
        $tables = [];
        $expr = (string) ($m['expression'] ?? '');
        if (preg_match_all('/\b([a-zA-Z_][a-zA-Z0-9_]*)\./', $expr, $mm)) {
            foreach ($mm[1] as $t) {
                $tables[$t] = true;
            }
        }
        $grain = (string) ($m['grain'] ?? '');
        $cal = DatasetProfile::calendar();
        $fact = (string) ($cal['fact_table'] ?? 'fact_orders');
        if ($tables === []) {
            // Generic fallbacks by grain name — still works for non-Olist if expressions include tables
            $tables[$fact] = true;
        } elseif ($fact !== '') {
            $tables[$fact] = true;
        }
        return array_keys($tables);
    }

    /** @param array<string,mixed> $m @return list<string> */
    private function joinsForMetric(array $m): array
    {
        $need = array_fill_keys($this->tablesForMetric($m), true);
        $ids = [];
        foreach (SemanticConfig::joins() as $j) {
            $left = (string) ($j['left_table'] ?? '');
            $right = (string) ($j['right_table'] ?? '');
            if (isset($need[$left]) || isset($need[$right])) {
                $id = (string) ($j['id'] ?? '');
                if ($id !== '') {
                    $ids[] = $id;
                }
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * @param list<string> $metrics
     * @param list<string> $dimensions
     * @param array<string,scalar|null> $filters
     * @return array<string,mixed>
     */
    private function buildSql(
        array $metrics,
        array $dimensions,
        ?string $timeGrain,
        ?string $dateFrom,
        ?string $dateTo,
        array $filters,
        bool $applyDefaultStatus,
        ?int $topN
    ): array {
        $metricDefs = $this->resolveMetrics($metrics);
        if ($metricDefs === []) {
            return ['ok' => false, 'errors' => ['No valid metrics. Use search_metrics for metric_id (e.g. gmv, order_count).']];
        }

        $split = $this->splitMetricsForFanOut($metricDefs);
        $metricDefs = $split['keep'];
        if ($metricDefs === []) {
            return [
                'ok' => false,
                'errors' => [$split['warning'] ?? 'Incompatible metrics'],
                'retry_hint' => 'Call analyze_* once per metric grain.',
            ];
        }

        $tables = [];
        $joinIds = [];
        foreach ($metricDefs as $m) {
            foreach ($this->tablesForMetric($m) as $t) {
                $tables[$t] = true;
            }
            foreach ($this->joinsForMetric($m) as $j) {
                $joinIds[$j] = true;
            }
        }

        $dimExprs = [];
        $dimMetas = [];
        foreach ($dimensions as $d) {
            $meta = $this->resolveField((string) $d);
            if ($meta === null) {
                return [
                    'ok' => false,
                    'errors' => ["Unknown dimension '{$d}'. Use search_metrics for dimension_id."],
                    'retry_hint' => 'search_metrics then analyze_breakdown with registry dimension_id.',
                ];
            }
            $dimExprs[$meta['alias']] = $meta['expr'];
            $dimMetas[] = $meta;
            foreach ($meta['tables'] as $t) {
                $tables[$t] = true;
            }
            foreach ($meta['joins'] as $j) {
                $joinIds[$j] = true;
            }
        }

        $grainTable = $this->grainTableForMetrics($metricDefs);
        $where = [];
        $filterInfo = [];
        $semiJoinNotes = [];
        foreach ($filters as $field => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $meta = $this->resolveField((string) $field);
            if ($meta === null) {
                return [
                    'ok' => false,
                    'errors' => ["Unknown filter field '{$field}'. Use search_metrics for dimension_id."],
                ];
            }
            $filterInfo[$meta['alias']] = $value;
            $filterTables = array_values(array_map('strval', $meta['tables']));
            // If attaching filter tables would 1:N-expand the metric grain, use EXISTS
            // (correct cardinality + avoids pathological row explosion).
            if ($grainTable !== '' && $this->pathFansOutFrom($grainTable, $filterTables)) {
                $exists = $this->buildExistsSemiJoin($grainTable, $meta, $value);
                if ($exists === null) {
                    return [
                        'ok' => false,
                        'errors' => [
                            "Filter '{$field}' requires a fan-out join from {$grainTable} "
                            . 'and no safe semi-join path was found.',
                        ],
                        'retry_hint' => 'find_join_path / register_join between grain and filter tables, then retry.',
                    ];
                }
                $where[] = $exists;
                $semiJoinNotes[] = $meta['alias'];
                continue;
            }
            foreach ($filterTables as $t) {
                $tables[$t] = true;
            }
            foreach ($meta['joins'] as $j) {
                $joinIds[$j] = true;
            }
            $where[] = "{$meta['expr']} = " . $this->sqlLiteral($value);
        }

        $cal = DatasetProfile::calendar();
        $dateCol = (string) ($cal['date_column'] ?? 'order_purchase_timestamp');
        $fact = (string) ($cal['fact_table'] ?? 'fact_orders');
        $dateExpr = str_contains($dateCol, '.') ? $dateCol : "{$fact}.{$dateCol}";
        $tables[$fact] = true;

        if ($dateFrom !== null && $dateFrom !== '') {
            $where[] = "{$dateExpr} >= " . $this->sqlLiteral($dateFrom);
        }
        if ($dateTo !== null && $dateTo !== '') {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
                $where[] = "{$dateExpr} < " . $this->sqlLiteral($dateTo . ' 23:59:59.999');
            } else {
                $where[] = "{$dateExpr} <= " . $this->sqlLiteral($dateTo);
            }
        }

        if ($applyDefaultStatus) {
            $defaults = DatasetProfile::defaults();
            $status = trim((string) ($defaults['status_filter_sql'] ?? ''));
            if ($status !== '') {
                $where[] = $status;
            }
        }

        // Dims that fan-out from grain while metrics stay on grain → keep join but
        // force distinct-order style metrics only; AVG on duplicated grain rows is unsafe.
        if ($grainTable !== '' && $this->metricsNeedDistinctGrain($metricDefs)) {
            foreach ($dimMetas as $meta) {
                if ($this->pathFansOutFrom($grainTable, $meta['tables'])) {
                    // Safe if all metrics are COUNT DISTINCT / already item-grain.
                    // Otherwise wrap: compute on deduped (grain_pk, dims) subquery.
                    if ($this->hasNonDistinctGrainAvg($metricDefs)) {
                        return $this->buildSqlDedupedFanOut(
                            $metricDefs,
                            $dimExprs,
                            $tables,
                            $joinIds,
                            $where,
                            $dateExpr,
                            $timeGrain,
                            $topN,
                            $filterInfo,
                            $dateFrom,
                            $dateTo,
                            $split['warning'],
                            $grainTable,
                            $semiJoinNotes
                        );
                    }
                }
            }
        }

        if ($fact !== '') {
            $needFact = false;
            foreach (array_keys($tables) as $t) {
                if ($t !== $fact && (str_starts_with($t, 'fact_') || str_starts_with($t, 'dim_'))) {
                    $needFact = true;
                    break;
                }
            }
            if ($needFact) {
                $tables[$fact] = true;
            }
        }

        $from = $this->buildFromClause(array_keys($tables), array_keys($joinIds));
        if (!$from['ok']) {
            return $from;
        }

        $select = [];
        $groupBy = [];
        $orderBy = null;

        if ($timeGrain !== null) {
            $periodExpr = match ($timeGrain) {
                'day' => "substr({$dateExpr}, 1, 10)",
                'year' => "substr({$dateExpr}, 1, 4)",
                default => "substr({$dateExpr}, 1, 7)",
            };
            $select[] = "{$periodExpr} AS period";
            $groupBy[] = 'period';
            $orderBy = 'period ASC';
        }

        foreach ($dimExprs as $alias => $expr) {
            $select[] = "{$expr} AS {$alias}";
            $groupBy[] = $alias;
        }

        $firstMetricAlias = null;
        foreach ($metricDefs as $m) {
            $alias = preg_replace('/[^a-z0-9_]/i', '_', (string) $m['id']) ?: 'metric';
            $select[] = "{$m['expression']} AS {$alias}";
            if ($firstMetricAlias === null) {
                $firstMetricAlias = $alias;
            }
        }

        if ($orderBy === null && $firstMetricAlias !== null && $dimExprs !== [] && $timeGrain === null) {
            $orderBy = "{$firstMetricAlias} DESC";
        } elseif ($topN !== null && $topN > 0 && $firstMetricAlias !== null && $timeGrain === null) {
            $orderBy = "{$firstMetricAlias} DESC";
        }

        $sql = 'SELECT ' . implode(",\n       ", $select)
            . "\nFROM {$from['sql']}";
        if ($where !== []) {
            $sql .= "\nWHERE " . implode("\n  AND ", $where);
        }
        if ($groupBy !== []) {
            $sql .= "\nGROUP BY " . implode(', ', $groupBy);
        }
        if ($orderBy !== null) {
            $sql .= "\nORDER BY {$orderBy}";
        }

        $joinMeta = $from['join_meta'] ?? [];
        if ($semiJoinNotes !== []) {
            $joinMeta['semi_join_filters'] = $semiJoinNotes;
            $joinMeta['fan_out_strategy'] = 'exists';
        }

        return [
            'ok' => true,
            'sql' => $sql,
            'metrics' => array_map(static fn ($m) => $m['id'], $metricDefs),
            'dimensions' => array_keys($dimExprs),
            'filters' => $filterInfo,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'time_grain' => $timeGrain,
            'top_n' => ($topN !== null && $topN > 0) ? (int) $topN : null,
            'full_data_scan' => true,
            'fanout_warning' => $split['warning'],
            'join_meta' => $joinMeta,
            'join_warnings' => $from['warnings'] ?? null,
        ];
    }

    /**
     * @param list<string> $requested
     * @return list<array<string,mixed>>
     */
    private function resolveMetrics(array $requested): array
    {
        if ($requested === []) {
            $defaults = DatasetProfile::defaults();
            $primary = (string) ($defaults['primary_metric_id'] ?? 'gmv');
            $requested = $primary !== '' ? [$primary, 'order_count'] : ['gmv', 'order_count'];
            $requested = array_values(array_unique($requested));
        }
        $out = [];
        foreach ($requested as $id) {
            $row = $this->registry->resolveMetric((string) $id);
            if ($row === null) {
                continue;
            }
            $out[] = [
                'id' => (string) $row['metric_id'],
                'expression' => (string) $row['expression'],
                'grain' => (string) ($row['grain'] ?? ''),
                'description' => (string) ($row['description'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Primary grain table for the kept metrics (drives fan-out decisions).
     *
     * @param list<array<string,mixed>> $metrics
     */
    private function grainTableForMetrics(array $metrics): string
    {
        $cal = DatasetProfile::calendar();
        $fact = (string) ($cal['fact_table'] ?? '');
        $grains = [];
        foreach ($metrics as $m) {
            $grains[] = strtolower((string) ($m['grain'] ?? 'order'));
        }
        $grains = array_values(array_unique($grains));
        if ($grains === ['order'] || $grains === []) {
            return $fact !== '' ? $fact : 'fact_orders';
        }
        if (in_array('order_item', $grains, true)) {
            // Prefer explicit item table from metric expr
            foreach ($metrics as $m) {
                foreach ($this->tablesForMetric($m) as $t) {
                    if ($t !== $fact && str_contains(strtolower($t), 'item')) {
                        return $t;
                    }
                }
            }
            foreach ($metrics as $m) {
                $tables = $this->tablesForMetric($m);
                if ($tables !== []) {
                    return $tables[0];
                }
            }
        }
        if (in_array('payment', $grains, true)) {
            foreach ($metrics as $m) {
                foreach ($this->tablesForMetric($m) as $t) {
                    if (str_contains(strtolower($t), 'payment')) {
                        return $t;
                    }
                }
            }
        }
        if (in_array('review', $grains, true)) {
            foreach ($metrics as $m) {
                foreach ($this->tablesForMetric($m) as $t) {
                    if (str_contains(strtolower($t), 'review')) {
                        return $t;
                    }
                }
            }
        }
        return $fact !== '' ? $fact : ($this->tablesForMetric($metrics[0] ?? [])[0] ?? '');
    }

    /**
     * True when walking from $fromTable to $targets crosses a 1→N edge
     * (N:1 join traversed from the "1" side).
     *
     * @param list<string> $targets
     */
    private function pathFansOutFrom(string $fromTable, array $targets): bool
    {
        $targets = array_values(array_filter(
            array_map('strval', $targets),
            static fn ($t) => $t !== '' && strcasecmp($t, $fromTable) !== 0
        ));
        if ($targets === []) {
            return false;
        }
        // Need any target not safely reachable via N→1 / 1→1 only
        $edges = SemanticConfig::joins();
        // BFS with direction-aware fan-out flag
        $queue = [[$fromTable, false]];
        $seen = [strtolower($fromTable) => false];
        while ($queue !== []) {
            [$node, $fan] = array_shift($queue);
            foreach ($targets as $t) {
                if (strcasecmp($node, $t) === 0 && $fan) {
                    return true;
                }
            }
            foreach ($edges as $j) {
                $left = (string) ($j['left_table'] ?? '');
                $right = (string) ($j['right_table'] ?? '');
                $card = strtoupper(trim((string) ($j['cardinality'] ?? 'N:1')));
                if ($left === '' || $right === '') {
                    continue;
                }
                $next = null;
                $stepFan = $fan;
                if (strcasecmp($node, $left) === 0) {
                    $next = $right;
                    // left→right on N:1 is many→one (safe)
                    if ($card === '1:N' || $card === 'N:N' || $card === 'M:N') {
                        $stepFan = true;
                    }
                } elseif (strcasecmp($node, $right) === 0) {
                    $next = $left;
                    // right→left on N:1 is one→many (fan-out)
                    if ($card === 'N:1' || $card === '1:N' || $card === 'N:N' || $card === 'M:N') {
                        // N:1 means left=N right=1; reverse walk fans out
                        if ($card === 'N:1') {
                            $stepFan = true;
                        } elseif ($card === '1:N') {
                            // left=1 right=N; reverse is safe
                            $stepFan = $fan;
                        } else {
                            $stepFan = true;
                        }
                    }
                }
                if ($next === null) {
                    continue;
                }
                $key = strtolower($next);
                // Prefer a safe (non-fan-out) path if one exists — do not let a later
                // reverse-edge visit mark an already-safe node as fan-out.
                if (!array_key_exists($key, $seen)) {
                    $seen[$key] = $stepFan;
                    $queue[] = [$next, $stepFan];
                } elseif ($seen[$key] === true && $stepFan === false) {
                    $seen[$key] = false;
                    $queue[] = [$next, false];
                }
            }
        }
        foreach ($targets as $t) {
            $key = strtolower($t);
            if (($seen[$key] ?? null) === true) {
                return true;
            }
            if (!array_key_exists($key, $seen)) {
                // unreachable via catalog — try EXISTS discovery instead of hard-failing fan flag
                return true;
            }
        }
        return false;
    }

    /**
     * EXISTS semi-join so filter tables do not multiply grain rows.
     * Path is discovered from the join catalog (dimension.tables may be incomplete).
     *
     * @param array{expr:string,alias:string,tables:list<string>,joins:list<string>} $filterMeta
     */
    private function buildExistsSemiJoin(string $grainTable, array $filterMeta, mixed $value): ?string
    {
        $anchorTables = $this->tablesMentionedInExpr($filterMeta['expr']);
        foreach ($filterMeta['tables'] as $t) {
            $t = (string) $t;
            if ($t !== '' && strcasecmp($t, $grainTable) !== 0) {
                $anchorTables[] = $t;
            }
        }
        $anchorTables = array_values(array_unique($anchorTables));
        if ($anchorTables === []) {
            return $filterMeta['expr'] . ' = ' . $this->sqlLiteral($value);
        }

        $bridge = $this->tablesOnPathExcludingGrain($grainTable, $anchorTables);
        if ($bridge === []) {
            return null;
        }

        $corr = $this->findGrainAttach($grainTable, $bridge);
        if ($corr === null) {
            return null;
        }

        $from = $this->buildFromClauseProfileOnly($bridge, []);
        if (!($from['ok'] ?? false)) {
            $from = $this->buildFromClauseLegacyPreferred($bridge, $filterMeta['joins'] ?? []);
        }
        if (!($from['ok'] ?? false)) {
            return null;
        }

        $lit = $this->sqlLiteral($value);
        return 'EXISTS (SELECT 1 FROM ' . $from['sql']
            . ' WHERE ' . $corr['child_table'] . '.' . $corr['child_key']
            . ' = ' . $grainTable . '.' . $corr['grain_key']
            . ' AND ' . $filterMeta['expr'] . ' = ' . $lit . ')';
    }

    /** @return list<string> */
    private function tablesMentionedInExpr(string $expr): array
    {
        $out = [];
        if (preg_match_all('/\b([a-zA-Z_][a-zA-Z0-9_]*)\./', $expr, $m)) {
            foreach ($m[1] as $t) {
                $out[] = $t;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * @param list<string> $anchors
     * @return list<string>
     */
    private function tablesOnPathExcludingGrain(string $grainTable, array $anchors): array
    {
        $adj = [];
        foreach (SemanticConfig::joins() as $j) {
            $l = (string) ($j['left_table'] ?? '');
            $r = (string) ($j['right_table'] ?? '');
            if ($l === '' || $r === '') {
                continue;
            }
            $adj[$l][] = $r;
            $adj[$r][] = $l;
        }

        $needed = [];
        foreach ($anchors as $anchor) {
            if (strcasecmp((string) $anchor, $grainTable) === 0) {
                continue;
            }
            $prev = [$grainTable => null];
            $q = [$grainTable];
            $found = false;
            while ($q !== []) {
                $u = array_shift($q);
                if (strcasecmp($u, (string) $anchor) === 0) {
                    $found = true;
                    break;
                }
                foreach ($adj[$u] ?? [] as $v) {
                    if (!array_key_exists($v, $prev)) {
                        $prev[$v] = $u;
                        $q[] = $v;
                    }
                }
            }
            if (!$found) {
                continue;
            }
            for ($cur = (string) $anchor; $cur !== '' && strcasecmp($cur, $grainTable) !== 0; $cur = (string) $prev[$cur]) {
                $needed[$cur] = true;
                if ($prev[$cur] === null) {
                    break;
                }
            }
        }
        return array_keys($needed);
    }

    /**
     * Find how a set of tables attaches to grain (N:1 child → grain).
     *
     * @param list<string> $candidateTables
     * @return array{child_table:string,child_key:string,grain_key:string}|null
     */
    private function findGrainAttach(string $grainTable, array $candidateTables): ?array
    {
        $set = array_fill_keys(array_map('strtolower', $candidateTables), true);
        foreach (SemanticConfig::joins() as $j) {
            $left = (string) ($j['left_table'] ?? '');
            $right = (string) ($j['right_table'] ?? '');
            $lk = (string) ($j['left_key'] ?? '');
            $rk = (string) ($j['right_key'] ?? '');
            $card = strtoupper(trim((string) ($j['cardinality'] ?? 'N:1')));
            if ($lk === '' || $rk === '') {
                continue;
            }
            // Classic: child(N) → grain(1)
            if (strcasecmp($right, $grainTable) === 0 && isset($set[strtolower($left)])
                && ($card === 'N:1' || $card === '')
            ) {
                return ['child_table' => $left, 'child_key' => $lk, 'grain_key' => $rk];
            }
            if (strcasecmp($left, $grainTable) === 0 && isset($set[strtolower($right)])
                && $card === '1:N'
            ) {
                return ['child_table' => $right, 'child_key' => $rk, 'grain_key' => $lk];
            }
        }
        return null;
    }

    /** Prefer explicit profile joins; fall back to join-graph assembler. */
    private function buildFromClauseLegacyPreferred(array $tables, array $joinIds): array
    {
        $legacy = $this->buildFromClauseProfileOnly($tables, $joinIds);
        if ($legacy['ok'] ?? false) {
            return $legacy;
        }
        return $this->buildFromClause($tables, $joinIds);
    }

    /**
     * Assemble FROM using only SemanticConfig profile/FK joins (no discovery graph).
     *
     * @param list<string> $tables
     * @param list<string> $joinIds
     * @return array{ok:bool,sql?:string,errors?:list<string>,join_meta?:array}
     */
    private function buildFromClauseProfileOnly(array $tables, array $joinIds): array
    {
        $tables = array_values(array_unique(array_filter($tables)));
        if ($tables === []) {
            return ['ok' => false, 'errors' => ['No tables']];
        }
        if (count($tables) === 1) {
            return ['ok' => true, 'sql' => $tables[0], 'join_meta' => ['source' => 'single']];
        }
        $tableSet = array_fill_keys($tables, true);
        $cal = DatasetProfile::calendar();
        $root = (string) ($cal['fact_table'] ?? '');
        if ($root === '' || !isset($tableSet[$root])) {
            // Prefer a fact_/child table as root when grain excluded
            $root = $tables[0];
            foreach ($tables as $t) {
                if (str_starts_with(strtolower($t), 'fact_')) {
                    $root = $t;
                    break;
                }
            }
        }
        $sql = $root;
        $included = [$root => true];
        $joins = SemanticConfig::joins();
        $wanted = array_fill_keys($joinIds, true);
        for ($pass = 0; $pass < 10; $pass++) {
            $progress = false;
            foreach ($joins as $j) {
                $id = (string) ($j['id'] ?? '');
                $left = (string) ($j['left_table'] ?? '');
                $right = (string) ($j['right_table'] ?? '');
                $lk = (string) ($j['left_key'] ?? '');
                $rk = (string) ($j['right_key'] ?? '');
                if ($left === '' || $right === '' || $lk === '' || $rk === '') {
                    continue;
                }
                $needLeft = isset($tableSet[$left]) && !isset($included[$left]);
                $needRight = isset($tableSet[$right]) && !isset($included[$right]);
                $haveLeft = isset($included[$left]);
                $haveRight = isset($included[$right]);
                if ($haveLeft && $needRight) {
                    $sql .= "\nJOIN {$right} ON {$right}.{$rk} = {$left}.{$lk}";
                    $included[$right] = true;
                    $progress = true;
                } elseif ($haveRight && $needLeft) {
                    $sql .= "\nJOIN {$left} ON {$left}.{$lk} = {$right}.{$rk}";
                    $included[$left] = true;
                    $progress = true;
                } elseif (isset($wanted[$id]) && $haveLeft && !$haveRight && isset($tableSet[$right])) {
                    $sql .= "\nJOIN {$right} ON {$right}.{$rk} = {$left}.{$lk}";
                    $included[$right] = true;
                    $progress = true;
                } elseif (isset($wanted[$id]) && $haveRight && !$haveLeft && isset($tableSet[$left])) {
                    $sql .= "\nJOIN {$left} ON {$left}.{$lk} = {$right}.{$rk}";
                    $included[$left] = true;
                    $progress = true;
                }
            }
            $missing = false;
            foreach ($tableSet as $t => $_) {
                if (!isset($included[$t])) {
                    $missing = true;
                    break;
                }
            }
            if (!$missing) {
                return ['ok' => true, 'sql' => $sql, 'join_meta' => ['source' => 'profile_joins']];
            }
            if (!$progress) {
                break;
            }
        }
        $miss = [];
        foreach ($tableSet as $t => $_) {
            if (!isset($included[$t])) {
                $miss[] = $t;
            }
        }
        return ['ok' => false, 'errors' => ['Could not join tables: ' . implode(', ', $miss)]];
    }

    /** @param list<array<string,mixed>> $metrics */
    private function metricsNeedDistinctGrain(array $metrics): bool
    {
        foreach ($metrics as $m) {
            $g = strtolower((string) ($m['grain'] ?? 'order'));
            if (in_array($g, ['order', 'payment', 'review'], true)) {
                return true;
            }
        }
        return false;
    }

    /** @param list<array<string,mixed>> $metrics */
    private function hasNonDistinctGrainAvg(array $metrics): bool
    {
        foreach ($metrics as $m) {
            $expr = strtoupper((string) ($m['expression'] ?? ''));
            $g = strtolower((string) ($m['grain'] ?? ''));
            if ($g === 'order_item') {
                continue;
            }
            if (str_contains($expr, 'AVG(') && !str_contains($expr, 'COUNT(DISTINCT')) {
                return true;
            }
            if (preg_match('/\bSUM\s*\(/', $expr) && !str_contains($expr, 'DISTINCT')
                && $g === 'order'
            ) {
                // SUM on order-level cols duplicated by item join would inflate
                return true;
            }
        }
        return false;
    }

    /**
     * When a dimension forces a fan-out join under grain-level AVG/SUM, dedupe
     * to one row per grain key (+ dims) before aggregating.
     *
     * @param list<array<string,mixed>> $metricDefs
     * @param array<string,string> $dimExprs
     * @param array<string,bool> $tables
     * @param array<string,bool> $joinIds
     * @param list<string> $where
     * @param array<string,mixed> $filterInfo
     * @param list<string> $semiJoinNotes
     * @return array<string,mixed>
     */
    private function buildSqlDedupedFanOut(
        array $metricDefs,
        array $dimExprs,
        array $tables,
        array $joinIds,
        array $where,
        string $dateExpr,
        ?string $timeGrain,
        ?int $topN,
        array $filterInfo,
        ?string $dateFrom,
        ?string $dateTo,
        ?string $fanoutWarning,
        string $grainTable,
        array $semiJoinNotes
    ): array {
        $pk = $this->inferGrainPk($grainTable);
        if ($pk === null) {
            return [
                'ok' => false,
                'errors' => [
                    "Fan-out dimension with grain AVG/SUM needs a primary key on {$grainTable}.",
                ],
                'retry_hint' => 'Use COUNT/DISTINCT metrics, or register grain PK / avoid mixing item dims with order AVGs.',
            ];
        }
        $from = $this->buildFromClause(array_keys($tables), array_keys($joinIds));
        if (!($from['ok'] ?? false)) {
            return $from;
        }
        $innerSelect = ["{$grainTable}.{$pk} AS _grain_pk"];
        foreach ($dimExprs as $alias => $expr) {
            $innerSelect[] = "{$expr} AS {$alias}";
        }
        // Pull raw (non-aggregated) pieces for AVG/SUM rewrite
        $metricInner = [];
        foreach ($metricDefs as $i => $m) {
            $expr = (string) ($m['expression'] ?? '');
            // AVG(x) → expose x; COUNT(DISTINCT y) → expose y; SUM(x) → expose x
            if (preg_match('/^AVG\s*\((.*)\)\s*$/is', $expr, $mm)) {
                $metricInner[] = ['alias' => '_m' . $i, 'inner' => $mm[1], 'outer' => 'AVG'];
            } elseif (preg_match('/^COUNT\s*\(\s*DISTINCT\s+(.*)\s*\)\s*$/is', $expr, $mm)) {
                $metricInner[] = ['alias' => '_m' . $i, 'inner' => $mm[1], 'outer' => 'COUNT_DISTINCT'];
            } elseif (preg_match('/^SUM\s*\((.*)\)\s*$/is', $expr, $mm)) {
                $metricInner[] = ['alias' => '_m' . $i, 'inner' => $mm[1], 'outer' => 'SUM'];
            } elseif (preg_match('/^COUNT\s*\(\s*\*\s*\)\s*$/is', $expr)) {
                $metricInner[] = ['alias' => '_m' . $i, 'inner' => '1', 'outer' => 'COUNT'];
            } else {
                // Fallback: keep expression on outer over deduped rows (best-effort)
                $metricInner[] = ['alias' => '_m' . $i, 'inner' => $expr, 'outer' => 'PASSTHROUGH', 'id' => $m['id']];
            }
            if (($metricInner[array_key_last($metricInner)]['outer'] ?? '') !== 'PASSTHROUGH') {
                $innerSelect[] = $metricInner[array_key_last($metricInner)]['inner']
                    . ' AS ' . $metricInner[array_key_last($metricInner)]['alias'];
            }
        }
        $inner = 'SELECT DISTINCT ' . implode(",\n       ", $innerSelect)
            . "\nFROM {$from['sql']}";
        if ($where !== []) {
            $inner .= "\nWHERE " . implode("\n  AND ", $where);
        }

        $outerSelect = [];
        $groupBy = [];
        if ($timeGrain !== null) {
            // rare with dedupe path; skip period for safety
        }
        foreach ($dimExprs as $alias => $_) {
            $outerSelect[] = $alias;
            $groupBy[] = $alias;
        }
        $firstMetricAlias = null;
        foreach ($metricDefs as $i => $m) {
            $id = preg_replace('/[^a-z0-9_]/i', '_', (string) $m['id']) ?: 'metric';
            $mi = $metricInner[$i];
            $outer = match ($mi['outer']) {
                'AVG' => "AVG({$mi['alias']})",
                'SUM' => "SUM({$mi['alias']})",
                'COUNT_DISTINCT' => "COUNT(DISTINCT {$mi['alias']})",
                'COUNT' => 'COUNT(*)',
                default => (string) ($m['expression'] ?? $mi['alias']),
            };
            $outerSelect[] = "{$outer} AS {$id}";
            $firstMetricAlias ??= $id;
        }
        $sql = 'SELECT ' . implode(",\n       ", $outerSelect)
            . "\nFROM (\n{$inner}\n) _deduped";
        if ($groupBy !== []) {
            $sql .= "\nGROUP BY " . implode(', ', $groupBy);
        }
        if ($firstMetricAlias !== null && $groupBy !== []) {
            $sql .= "\nORDER BY {$firstMetricAlias} DESC";
        }

        return [
            'ok' => true,
            'sql' => $sql,
            'metrics' => array_map(static fn ($m) => $m['id'], $metricDefs),
            'dimensions' => array_keys($dimExprs),
            'filters' => $filterInfo,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'time_grain' => $timeGrain,
            'top_n' => ($topN !== null && $topN > 0) ? (int) $topN : null,
            'full_data_scan' => true,
            'fanout_warning' => $fanoutWarning,
            'join_meta' => [
                'source' => $from['join_meta']['source'] ?? 'join_graph',
                'fan_out_strategy' => 'dedupe_grain',
                'semi_join_filters' => $semiJoinNotes,
                'grain_table' => $grainTable,
                'grain_pk' => $pk,
            ],
        ];
    }

    private function inferGrainPk(string $grainTable): ?string
    {
        foreach (SemanticConfig::joins() as $j) {
            $card = strtoupper(trim((string) ($j['cardinality'] ?? 'N:1')));
            if (strcasecmp((string) ($j['right_table'] ?? ''), $grainTable) === 0 && $card === 'N:1') {
                $rk = (string) ($j['right_key'] ?? '');
                if ($rk !== '') {
                    return $rk;
                }
            }
            if (strcasecmp((string) ($j['left_table'] ?? ''), $grainTable) === 0 && $card === '1:N') {
                $lk = (string) ($j['left_key'] ?? '');
                if ($lk !== '') {
                    return $lk;
                }
            }
        }
        if (preg_match('/order/i', $grainTable)) {
            return 'order_id';
        }
        return 'id';
    }

    /**
     * @param list<string> $tables
     * @param list<string> $joinIds
     * @param list<string>|null $joinPathEdgeIds Optional planner edge ids / explicit path
     * @return array{ok:bool,sql?:string,errors?:list<string>,warnings?:list<string>,join_meta?:array}
     */
    private function buildFromClause(array $tables, array $joinIds, ?array $joinPathEdgeIds = null): array
    {
        $tables = array_values(array_unique(array_filter($tables)));
        if ($tables === []) {
            return ['ok' => false, 'errors' => ['No tables resolved for query']];
        }

        // 1) Prefer declared profile/FK joins (stable, dataset-provided)
        if (count($tables) >= 2) {
            $profile = $this->buildFromClauseProfileOnly($tables, $joinIds);
            if ($profile['ok'] ?? false) {
                return $profile;
            }
        }

        // 2) Discovery join-graph for cross-domain / undeclared paths
        if (count($tables) >= 2) {
            try {
                $planned = $this->planner->buildFromForTables($tables);
                if ($planned['ok'] ?? false) {
                    return [
                        'ok' => true,
                        'sql' => (string) $planned['sql'],
                        'warnings' => $planned['warnings'] ?? [],
                        'join_meta' => [
                            'source' => 'join_graph',
                            'path' => $planned['path'] ?? null,
                            'join_path' => $joinPathEdgeIds,
                        ],
                    ];
                }
                if (!empty($planned['ask_user_hint'])) {
                    return [
                        'ok' => false,
                        'errors' => $planned['errors'] ?? ['Join needs confirmation'],
                        'retry_hint' => $planned['ask_user_hint'],
                        'join_meta' => ['path' => $planned['path'] ?? null],
                    ];
                }
            } catch (\Throwable) {
                // fall through
            }
        }

        if (count($tables) === 1) {
            return ['ok' => true, 'sql' => $tables[0], 'join_meta' => ['source' => 'single']];
        }

        return [
            'ok' => false,
            'errors' => ['Could not join tables: ' . implode(', ', $tables)
                . '. Use find_join_path / register_join.'],
            'retry_hint' => 'find_join_path with the required table_ids, then retry analyze_*',
        ];
    }

    private function sqlLiteral(mixed $value): string
    {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        $s = (string) $value;
        return "'" . str_replace("'", "''", $s) . "'";
    }

    /**
     * @param array<string,mixed> $result
     * @param array<string,mixed> $built
     */
    private function attachMeta(array $result, array $built, string $tool): array
    {
        if (!($result['ok'] ?? false)) {
            $result['tool'] = $tool;
            $result['sql_used'] = $built['sql'] ?? null;
            return $result;
        }

        // Optional top_n: trim returned groups only (aggregation already scanned full data).
        $topN = isset($built['top_n']) ? (int) $built['top_n'] : 0;
        $totalGroups = (int) ($result['meta']['row_count'] ?? count($result['table']['rows'] ?? []));
        if ($topN > 0 && isset($result['table']['rows']) && is_array($result['table']['rows'])) {
            $result['table']['rows'] = array_slice($result['table']['rows'], 0, $topN);
            $result['meta']['row_count'] = count($result['table']['rows']);
            $result['meta']['groups_before_top_n'] = $totalGroups;
        }

        $result['tool'] = $tool;
        $result['sql_used'] = $built['sql'] ?? null;
        $result['meta'] = array_merge($result['meta'] ?? [], [
            'full_data_scan' => true,
            'execution_mode' => 'aggregate',
            'metrics' => $built['metrics'] ?? [],
            'dimensions' => $built['dimensions'] ?? [],
            'filters' => $built['filters'] ?? [],
            'date_from' => $built['date_from'] ?? null,
            'date_to' => $built['date_to'] ?? null,
            'time_grain' => $built['time_grain'] ?? null,
            'top_n' => $built['top_n'] ?? null,
            'partition_by' => $built['partition_by'] ?? null,
            'rank_dimension' => $built['rank_dimension'] ?? null,
            'top_n_per_group' => $built['top_n_per_group'] ?? null,
            'analysis_tool' => $tool,
            'join_meta' => $built['join_meta'] ?? null,
        ]);
        if (!empty($built['fanout_warning'])) {
            $result['meta']['warnings'] = array_values(array_filter(array_merge(
                $result['meta']['warnings'] ?? [],
                [$built['fanout_warning']]
            )));
        }
        $result['full_data_scan'] = true;
        return $result;
    }
}
