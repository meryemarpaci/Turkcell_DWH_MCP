<?php
require __DIR__ . '/../php/bootstrap.php';

use App\Tools\LlmPayload;

$browse = [
    'ok' => true,
    'report_type' => 'browse',
    'delivery' => 'ui_only',
    'title' => 'Ilk 100',
    'kpi' => [],
    'series' => [],
    'table' => ['columns' => ['a'], 'rows' => [['a' => 1], ['a' => 2]]],
    'meta' => ['row_count' => 100],
];
$b = LlmPayload::analysisResultForLlm($browse);
echo 'browse_has_rows=' . (isset($b['table']) || isset($b['table_sample']) ? 'yes' : 'no') . PHP_EOL;
echo 'browse_delivery=' . ($b['delivery'] ?? '') . PHP_EOL;

$points = [];
for ($i = 1; $i <= 24; $i++) {
    $points[] = ['x' => (string) $i, 'y' => $i];
}
$trend = [
    'ok' => true,
    'report_type' => 'trend',
    'delivery' => 'summary',
    'kpi' => [['name' => 'x', 'value' => 1]],
    'series' => [['name' => 'g', 'points' => $points]],
    'table' => ['columns' => ['m', 'g'], 'rows' => []],
    'meta' => ['row_count' => 24],
];
$t = LlmPayload::analysisResultForLlm($trend);
echo 'trend_mode=' . ($t['series_summary'][0]['mode'] ?? '?') . PHP_EOL;
echo 'trend_bytes=' . strlen(json_encode($t)) . PHP_EOL;

$smallRows = [];
for ($i = 1; $i <= 5; $i++) {
    $smallRows[] = ['cat' => 'c' . $i, 'gmv' => $i * 10];
}
$small = [
    'ok' => true,
    'report_type' => 'table',
    'delivery' => 'summary',
    'kpi' => [],
    'series' => [],
    'table' => ['columns' => ['cat', 'gmv'], 'rows' => $smallRows],
    'numeric_stats' => ['gmv' => ['min' => 10, 'max' => 50, 'sum' => 150, 'avg' => 30]],
    'meta' => ['row_count' => 5],
];
$s = LlmPayload::analysisResultForLlm($small);
echo 'small_mode=' . ($s['table']['mode'] ?? '?') . ' rows=' . count($s['table']['rows'] ?? []) . PHP_EOL;
