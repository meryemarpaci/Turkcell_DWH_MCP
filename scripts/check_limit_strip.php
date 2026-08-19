<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/php/bootstrap.php';

use App\SemanticConfig;
use App\Tools\ReportTool;
use App\Tools\SqlGuard;

$g = new SqlGuard(SemanticConfig::allowedTables());
$r = new ReportTool($g);

$sql = <<<'SQL'
WITH SellerProductState AS (
    SELECT 
        i.seller_id,
        p.category_name_english,
        c.customer_state,
        SUM(i.price) as total_sales,
        COUNT(DISTINCT i.order_id) as order_qty
    FROM fact_order_items i
    JOIN fact_orders o ON i.order_id = o.order_id
    JOIN dim_customer c ON o.customer_id = c.customer_id
    JOIN dim_product p ON i.product_id = p.product_id
    WHERE o.order_status = 'delivered'
    GROUP BY 1, 2, 3
),
TopProductPerSeller AS (
    SELECT 
        seller_id,
        category_name_english,
        customer_state,
        total_sales,
        order_qty,
        ROW_NUMBER() OVER(PARTITION BY seller_id ORDER BY total_sales DESC) as rnk
    FROM SellerProductState
)
SELECT 
    category_name_english,
    customer_state,
    SUM(total_sales) as aggregate_gmv,
    COUNT(DISTINCT seller_id) as seller_count,
    SUM(order_qty) as total_orders
FROM TopProductPerSeller
WHERE rnk = 1
GROUP BY 1, 2
ORDER BY aggregate_gmv DESC
LIMIT 50
SQL;

$strip = $g->stripTrailingLimit($sql);
if (!$strip['removed'] || $strip['removed_limit'] !== 50) {
    fwrite(STDERR, 'FAIL: expected LIMIT 50 strip, got ' . json_encode($strip) . PHP_EOL);
    exit(1);
}

$out = $r->runReport($sql, 'table', 'seller_top', 'Seller top product by state', 50);
echo 'mode=' . ($out['meta']['execution_mode'] ?? '?')
    . ' full=' . json_encode($out['meta']['full_data_scan'] ?? null)
    . ' rows=' . ($out['meta']['row_count'] ?? 0)
    . ' max=' . ($out['meta']['max_rows'] ?? 0)
    . ' stripped=' . json_encode($out['meta']['stripped_sql_limit'] ?? null)
    . PHP_EOL;

if (!($out['ok'] ?? false)) {
    fwrite(STDERR, 'FAIL query: ' . json_encode($out, JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(1);
}
if (($out['meta']['stripped_sql_limit'] ?? null) !== 50) {
    fwrite(STDERR, "FAIL: stripped_sql_limit should be 50\n");
    exit(1);
}
if (($out['meta']['row_count'] ?? 0) <= 50 && ($out['meta']['max_rows'] ?? 0) <= 50) {
    // Could still be <=50 real groups; check max_rows was bumped
    if (($out['meta']['max_rows'] ?? 0) < 500) {
        fwrite(STDERR, "FAIL: aggregate max_rows should be high after ignoring 50\n");
        exit(1);
    }
}
if (($out['meta']['row_count'] ?? 0) <= 50) {
    echo "NOTE: result groups <=50 naturally; max_rows=" . ($out['meta']['max_rows'] ?? 0) . PHP_EOL;
}

echo "LIMIT-strip full-data check OK\n";
