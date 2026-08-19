<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/php/bootstrap.php';

use App\Mcp\DwhToolRegistry;
use App\Tools\DimensionCatalog;

DimensionCatalog::reset();
echo 'dims=' . implode(',', DimensionCatalog::ids()) . PHP_EOL;

$r = DwhToolRegistry::dispatch('analyze_breakdown', [
    'dimensions' => ['seller_id'],
    'metrics' => ['gmv', 'order_count'],
    'top_n' => 5,
    'title' => 'top sellers',
]);
echo 'seller_id ok=' . json_encode($r['ok'] ?? false)
    . ' rows=' . ($r['meta']['row_count'] ?? 0)
    . ' pres=' . ($r['presentation'] ?? '')
    . ' chart=' . ($r['chart_kind'] ?? '')
    . PHP_EOL;
if (!($r['ok'] ?? false)) {
    fwrite(STDERR, json_encode($r, JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(1);
}

$m = DwhToolRegistry::dispatch('analyze_breakdown', [
    'dimensions' => ['seller_state'],
    'metrics' => ['gmv', 'avg_review_score'],
]);
echo 'fanout mix ok=' . json_encode($m['ok'] ?? false)
    . ' metrics=' . json_encode($m['meta']['metrics'] ?? null)
    . ' warn=' . json_encode(($m['meta']['warnings'][0] ?? null), JSON_UNESCAPED_UNICODE)
    . PHP_EOL;

$t = DwhToolRegistry::dispatch('analyze_top_per_group', [
    'partition_by' => 'seller',
    'rank_dimension' => 'gmv',
    'metrics' => ['gmv'],
]);
echo 'bad rank hint=' . ($t['retry_hint'] ?? $t['errors'][0] ?? '?') . PHP_EOL;

echo 'agent tools=' . implode(',', array_column(DwhToolRegistry::toolSchemas(), 'name')) . PHP_EOL;
echo 'OK\n';
