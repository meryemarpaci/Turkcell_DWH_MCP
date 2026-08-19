<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/php/bootstrap.php';

use App\Discovery\JoinGraphBuilder;
use App\Discovery\QueryPlanner;
use App\Discovery\TableProfiler;
use App\Mcp\DwhToolRegistry;
use App\Tools\AnalyticsTool;
use App\Tools\ReportTool;
use App\Tools\SqlGuard;
use App\SemanticConfig;

$profiler = new TableProfiler();
$profile = $profiler->profileAll();
echo 'profiled=' . ($profile['profiled'] ?? 0) . PHP_EOL;
if (($profile['profiled'] ?? 0) < 3) {
    fwrite(STDERR, "FAIL: expected several table cards\n");
    exit(1);
}

$graph = new JoinGraphBuilder($profiler);
$built = $graph->rebuild(false);
echo 'edges=' . ($built['edges'] ?? 0) . ' source=profile+name' . PHP_EOL;
if (($built['edges'] ?? 0) < 1) {
    fwrite(STDERR, "FAIL: expected join edges\n");
    exit(1);
}

$planner = new QueryPlanner($profiler, null, $graph);
$search = $planner->searchTables('customer order', 8);
echo 'search_tables n=' . count($search['tables'] ?? []) . PHP_EOL;

$path = $planner->findJoinPath(['fact_orders', 'dim_customer', 'fact_order_items'], false);
echo 'join_path ok=' . json_encode($path['ok'] ?? false)
    . ' conf=' . ($path['confidence'] ?? '?')
    . ' fan=' . ($path['fan_out_risk'] ?? '?')
    . ' edges=' . count($path['edges'] ?? [])
    . PHP_EOL;
if (!($path['ok'] ?? false) && empty($path['needs_confirmation'])) {
    fwrite(STDERR, json_encode($path, JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(1);
}

$from = $planner->buildFromForTables(['fact_orders', 'dim_customer']);
echo 'from_sql=' . str_replace("\n", ' ', (string) ($from['sql'] ?? '')) . PHP_EOL;
if (!($from['ok'] ?? false)) {
    fwrite(STDERR, json_encode($from, JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(1);
}

$viaMcp = DwhToolRegistry::dispatch('search_tables', ['query' => 'seller']);
echo 'mcp search ok=' . json_encode($viaMcp['ok'] ?? false)
    . ' n=' . count($viaMcp['tables'] ?? [])
    . PHP_EOL;

$guard = new SqlGuard(SemanticConfig::allowedTables());
$analytics = new AnalyticsTool(new ReportTool($guard));
$kpi = $analytics->analyzeKpi(['gmv', 'order_count'], null, null, [], true, 'discovery kpi');
echo 'analyze via join_graph ok=' . json_encode($kpi['ok'] ?? false)
    . ' full=' . json_encode($kpi['meta']['full_data_scan'] ?? null)
    . PHP_EOL;
if (!($kpi['ok'] ?? false)) {
    fwrite(STDERR, json_encode($kpi, JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(1);
}

$names = array_column(DwhToolRegistry::toolSchemas(), 'name');
echo 'agent_tools=' . implode(',', $names) . PHP_EOL;
foreach (['search_tables', 'describe_table', 'find_join_path', 'register_join'] as $need) {
    if (!in_array($need, $names, true)) {
        fwrite(STDERR, "FAIL missing tool {$need}\n");
        exit(1);
    }
}

echo "discovery/join graph OK\n";
