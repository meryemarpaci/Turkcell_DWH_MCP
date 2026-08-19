<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/php/bootstrap.php';

use App\SemanticConfig;
use App\Tools\ReportTool;
use App\Tools\SqlGuard;

$g = new SqlGuard(SemanticConfig::allowedTables());
$r = new ReportTool($g);

$sql = <<<SQL
SELECT c.customer_state,
       COUNT(DISTINCT o.order_id) AS order_count,
       ROUND(SUM(i.price), 2) AS gmv
FROM fact_orders o
JOIN dim_customer c ON c.customer_id = o.customer_id
JOIN fact_order_items i ON i.order_id = o.order_id
WHERE o.order_status = 'delivered'
GROUP BY c.customer_state
ORDER BY gmv DESC
SQL;

$agg = $r->runReport($sql, 'table', 'states', 'GMV by state');
echo 'AGG mode=' . ($agg['meta']['execution_mode'] ?? '?')
    . ' full=' . json_encode($agg['meta']['full_data_scan'] ?? null)
    . ' rows=' . ($agg['meta']['row_count'] ?? 0)
    . ' max=' . ($agg['meta']['max_rows'] ?? 0)
    . PHP_EOL;
echo 'top3=' . json_encode(array_slice($agg['table']['rows'] ?? [], 0, 3), JSON_UNESCAPED_UNICODE) . PHP_EOL;

// Simulate old bad habit: max_rows=20 on aggregate — still full scan, but groups capped at 20
$capped = $r->runReport($sql, 'table', 'states20', 'GMV by state capped', 20);
echo 'CAPPED20 rows=' . ($capped['meta']['row_count'] ?? 0)
    . ' truncated=' . json_encode($capped['meta']['truncated'] ?? null)
    . ' full=' . json_encode($capped['meta']['full_data_scan'] ?? null)
    . PHP_EOL;

$peek = $r->executeQuery('SELECT * FROM fact_orders', 10, 'peek');
echo 'PEEK rows=' . ($peek['row_count'] ?? 0)
    . ' mode=' . ($peek['execution_mode'] ?? '?')
    . ' max=' . ($peek['max_rows'] ?? 0)
    . PHP_EOL;

$raw = $r->runReport('SELECT order_id, order_status FROM fact_orders', 'kpi', 'bad', 'raw as kpi');
echo 'RAW-as-kpi ok=' . json_encode($raw['ok'] ?? null)
    . ' need_agg=' . json_encode($raw['need_aggregate'] ?? null)
    . PHP_EOL;
if (($raw['ok'] ?? true) !== false || empty($raw['need_aggregate'])) {
    fwrite(STDERR, "FAIL: raw kpi should be rejected with need_aggregate\n");
    exit(1);
}

// kpi + max_rows=20 must still return all groups when aggregated
$kpi = $r->runReport($sql, 'kpi', 'states_kpi', 'GMV by state kpi', 20);
echo 'KPI20 mode=' . ($kpi['meta']['execution_mode'] ?? '?')
    . ' rows=' . ($kpi['meta']['row_count'] ?? 0)
    . ' max=' . ($kpi['meta']['max_rows'] ?? 0)
    . PHP_EOL;
if (($kpi['meta']['row_count'] ?? 0) < 20 || ($kpi['meta']['max_rows'] ?? 0) < 1000) {
    fwrite(STDERR, "FAIL: kpi should ignore low max_rows\n");
    exit(1);
}

// Without max_rows, aggregate should return all states (~27), not 20/40
if (($agg['meta']['row_count'] ?? 0) < 20) {
    fwrite(STDERR, "FAIL: expected many state groups\n");
    exit(1);
}
if (($agg['meta']['execution_mode'] ?? '') !== 'aggregate' || !($agg['meta']['full_data_scan'] ?? false)) {
    fwrite(STDERR, "FAIL: aggregate mode/full_data_scan\n");
    exit(1);
}
if (($peek['row_count'] ?? 0) > 10) {
    fwrite(STDERR, "FAIL: peek exceeded 10\n");
    exit(1);
}

echo "full-data mode checks OK\n";

