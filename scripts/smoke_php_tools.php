<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/php/bootstrap.php';

use App\SemanticConfig;
use App\Tools\ProbeTool;
use App\Tools\ReportTool;
use App\Tools\SchemaTool;
use App\Tools\SqlGuard;

$guard = new SqlGuard(SemanticConfig::allowedTables());
$schema = new SchemaTool();
$probe = new ProbeTool($guard);
$report = new ReportTool($guard);

echo "schema tables: " . count($schema->listSchema()['tables']) . PHP_EOL;

$j = $probe->probeJoin(['items_orders', 'orders_customer']);
echo "probe_join ok=" . json_encode($j['ok']) . " rows=" . ($j['row_count'] ?? 0) . PHP_EOL;
if (!$j['ok']) {
    echo json_encode($j, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(1);
}

$sql = <<<SQL
SELECT c.customer_state,
       COUNT(DISTINCT o.order_id) AS order_count,
       ROUND(SUM(i.price), 2) AS gmv
FROM fact_orders o
JOIN dim_customer c ON c.customer_id = o.customer_id
JOIN fact_order_items i ON i.order_id = o.order_id
WHERE c.customer_state = 'SP'
GROUP BY c.customer_state
SQL;

$f = $probe->probeFilter($sql);
echo "probe_filter ok=" . json_encode($f['ok']) . " rows=" . ($f['row_count'] ?? 0) . PHP_EOL;

$r = $report->runReport($sql, 'kpi', 'sp_gmv', 'SP GMV');
echo "run_report ok=" . json_encode($r['ok']) . " kpi=" . json_encode($r['kpi'] ?? []) . PHP_EOL;

echo "PHP tools smoke OK\n";
