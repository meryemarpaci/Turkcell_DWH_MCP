<?php
require dirname(__DIR__) . '/php/bootstrap.php';
$pdo = App\Tools\Database::pdo();

$queries = [
    'max_date' => "SELECT MAX(order_purchase_timestamp) FROM fact_orders",
    'sep2018_all' => "SELECT COUNT(*) FROM fact_orders WHERE order_purchase_timestamp >= '2018-09-01' AND order_purchase_timestamp < '2018-10-01'",
    'sep2018_delivered' => "SELECT COUNT(*) FROM fact_orders WHERE order_status='delivered' AND order_purchase_timestamp >= '2018-09-01' AND order_purchase_timestamp < '2018-10-01'",
    'sep2018_sp' => "SELECT COUNT(*) FROM fact_orders o JOIN dim_customer c ON o.customer_id=c.customer_id WHERE c.customer_state='SP' AND o.order_purchase_timestamp >= '2018-09-01' AND o.order_purchase_timestamp < '2018-10-01'",
    'aug2018_sp' => "SELECT COUNT(*) FROM fact_orders o JOIN dim_customer c ON o.customer_id=c.customer_id WHERE c.customer_state='SP' AND o.order_purchase_timestamp >= '2018-08-01' AND o.order_purchase_timestamp < '2018-09-01'",
    'oct2018_sp' => "SELECT COUNT(*) FROM fact_orders o JOIN dim_customer c ON o.customer_id=c.customer_id WHERE c.customer_state='SP' AND o.order_purchase_timestamp >= '2018-10-01' AND o.order_purchase_timestamp < '2018-11-01'",
    'by_month_sp_2018' => "SELECT substr(o.order_purchase_timestamp,1,7) m, COUNT(*) n FROM fact_orders o JOIN dim_customer c ON o.customer_id=c.customer_id WHERE c.customer_state='SP' AND o.order_purchase_timestamp LIKE '2018%' GROUP BY 1 ORDER BY 1",
    'sample_states' => "SELECT customer_state, COUNT(*) n FROM dim_customer GROUP BY 1 ORDER BY n DESC LIMIT 8",
];

foreach ($queries as $name => $sql) {
    echo "=== $name ===\n";
    try {
        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) === 1 && count($rows[0]) === 1) {
            echo reset($rows[0]) . "\n";
        } else {
            foreach ($rows as $r) {
                echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
            }
        }
    } catch (Throwable $e) {
        echo "ERR: " . $e->getMessage() . "\n";
    }
}
