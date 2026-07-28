<?php
require dirname(__DIR__) . '/php/bootstrap.php';
$pdo = App\Tools\Database::pdo();
$sql = "SELECT COUNT(DISTINCT o.order_id) orders, ROUND(SUM(i.price),2) gmv
FROM fact_orders o
JOIN dim_customer c ON o.customer_id=c.customer_id
JOIN fact_order_items i ON o.order_id=i.order_id
WHERE c.customer_state='SP'
  AND o.order_status='delivered'
  AND o.order_purchase_timestamp>='2018-08-01'
  AND o.order_purchase_timestamp<'2018-09-01'";
print_r($pdo->query($sql)->fetch(PDO::FETCH_ASSOC));

$sql2 = "SELECT substr(order_purchase_timestamp,1,7) m, COUNT(*) n,
  SUM(CASE WHEN order_status='delivered' THEN 1 ELSE 0 END) delivered
FROM fact_orders
WHERE order_purchase_timestamp>='2018-06-01'
GROUP BY 1 ORDER BY 1";
foreach ($pdo->query($sql2) as $r) {
    echo json_encode($r) . "\n";
}
