<?php
require dirname(__DIR__) . '/php/bootstrap.php';
$pdo = App\Tools\Database::pdo();
$min = $pdo->query('SELECT MIN(order_purchase_timestamp) FROM fact_orders')->fetchColumn();
$max = $pdo->query('SELECT MAX(order_purchase_timestamp) FROM fact_orders')->fetchColumn();
echo "min=$min\nmax=$max\n";
