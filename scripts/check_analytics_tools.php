<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/php/bootstrap.php';

use App\DataCalendar;
use App\Mcp\DwhToolRegistry;
use App\SemanticConfig;
use App\Tools\AnalyticsTool;
use App\Tools\ReportTool;
use App\Tools\SqlGuard;

$guard = new SqlGuard(SemanticConfig::allowedTables());
$analytics = new AnalyticsTool(new ReportTool($guard));
$cal = DataCalendar::info();

$kpi = $analytics->analyzeKpi(
    ['gmv', 'order_count'],
    $cal['prev_month_start'],
    $cal['prev_month_end'],
    ['customer_state' => 'SP'],
    true,
    'SP prev month'
);
echo 'KPI ok=' . json_encode($kpi['ok'] ?? false)
    . ' full=' . json_encode($kpi['meta']['full_data_scan'] ?? null)
    . ' kpi=' . json_encode($kpi['kpi'] ?? [], JSON_UNESCAPED_UNICODE)
    . PHP_EOL;
if (!($kpi['ok'] ?? false)) {
    fwrite(STDERR, json_encode($kpi, JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(1);
}

$br = $analytics->analyzeBreakdown(
    ['gmv', 'order_count'],
    ['customer_state'],
    null,
    null,
    [],
    true,
    null,
    'GMV by state'
);
echo 'BREAKDOWN ok=' . json_encode($br['ok'] ?? false)
    . ' rows=' . ($br['meta']['row_count'] ?? 0)
    . ' full=' . json_encode($br['meta']['full_data_scan'] ?? null)
    . PHP_EOL;
if (($br['meta']['row_count'] ?? 0) < 20) {
    fwrite(STDERR, "FAIL: expected many states\n");
    exit(1);
}

$tr = $analytics->analyzeTrend(
    ['gmv'],
    'month',
    $cal['latest_year'] . '-01-01',
    $cal['latest_month_end'],
    ['customer_state' => 'SP'],
    true,
    'SP monthly'
);
echo 'TREND ok=' . json_encode($tr['ok'] ?? false)
    . ' points=' . count($tr['series'][0]['points'] ?? [])
    . ' full=' . json_encode($tr['meta']['full_data_scan'] ?? null)
    . PHP_EOL;

$viaMcp = DwhToolRegistry::dispatch('analyze_breakdown', [
    'metrics' => ['gmv'],
    'dimensions' => ['category'],
    'filters' => ['customer_state' => 'SP'],
    'date_from' => $cal['prev_month_start'],
    'date_to' => $cal['prev_month_end'],
    'top_n' => 5,
    'title' => 'SP categories',
]);
echo 'MCP breakdown top5 rows=' . ($viaMcp['meta']['row_count'] ?? 0)
    . ' groups_before=' . ($viaMcp['meta']['groups_before_top_n'] ?? '?')
    . ' full=' . json_encode($viaMcp['meta']['full_data_scan'] ?? null)
    . PHP_EOL;

$top = DwhToolRegistry::dispatch('analyze_top_per_group', [
    'partition_by' => 'seller',
    'rank_dimension' => 'category',
    'extra_dimensions' => ['seller_state'],
    'metrics' => ['gmv', 'order_count'],
    'top_n_per_group' => 1,
    'title' => 'Her magaza en cok satan kategori',
]);
echo 'TOP_PER_GROUP ok=' . json_encode($top['ok'] ?? false)
    . ' rows=' . ($top['meta']['row_count'] ?? 0)
    . ' truncated=' . json_encode($top['meta']['truncated'] ?? null)
    . ' full=' . json_encode($top['meta']['full_data_scan'] ?? null)
    . ' partition=' . ($top['meta']['partition_by'] ?? '?')
    . PHP_EOL;
if (!($top['ok'] ?? false)) {
    fwrite(STDERR, json_encode($top, JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(1);
}
if (($top['meta']['row_count'] ?? 0) < 500) {
    fwrite(STDERR, "FAIL: expected one row per seller (hundreds+)\n");
    exit(1);
}
if (empty($top['rollup']['by_state']) || empty($top['rollup']['coverage'])) {
    fwrite(STDERR, "FAIL: missing rollup for LLM\n");
    exit(1);
}
$compact = \App\Tools\LlmPayload::compactToolResult('analyze_top_per_group', $top);
echo 'LLM payload has rollup=' . json_encode(isset($compact['rollup']))
    . ' entities=' . ($compact['meta']['entities_ranked'] ?? '?')
    . ' states=' . count($compact['rollup']['by_state'] ?? [])
    . ' note=' . ($compact['note'] ?? '')
    . PHP_EOL;
if (!isset($compact['rollup'])) {
    fwrite(STDERR, "FAIL: LLM compact missing rollup\n");
    exit(1);
}

$names = array_column(DwhToolRegistry::toolSchemas(), 'name');
echo 'tools=' . implode(',', $names) . PHP_EOL;

if (!($tr['ok'] ?? false) || !($viaMcp['ok'] ?? false)) {
    fwrite(STDERR, "FAIL trend/mcp\n");
    exit(1);
}

echo "analytics tools OK\n";

