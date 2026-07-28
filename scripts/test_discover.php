<?php
require __DIR__ . '/../php/bootstrap.php';
$p = App\DatasetProfile::get();
echo 'id=' . $p['id'] . PHP_EOL;
echo 'tables=' . count($p['allowed_tables'] ?? []) . PHP_EOL;
echo 'joins=' . count($p['joins'] ?? []) . PHP_EOL;
echo 'discovery=' . json_encode($p['discovery'] ?? null) . PHP_EOL;
$d = App\SchemaDiscoverer::fromSqlite(APP_ROOT . '/Data/olist_dwh.sqlite');
echo 'disc_tables=' . count($d['allowed_tables']) . ' disc_joins=' . count($d['joins']) . PHP_EOL;
echo 'cal=' . json_encode($d['calendar']) . PHP_EOL;
