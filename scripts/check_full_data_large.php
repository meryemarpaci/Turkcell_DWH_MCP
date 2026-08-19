<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/php/bootstrap.php';

use App\Tools\FullDataContract;
use App\Tools\IndexBootstrap;
use App\Mcp\DwhToolRegistry;

$idx = IndexBootstrap::ensure();
echo 'indexes ok=' . json_encode($idx['ok'] ?? false)
    . ' created=' . count($idx['created'] ?? [])
    . PHP_EOL;

$cases = [
    ['analyze_kpi', ['metrics' => ['gmv', 'order_count'], 'title' => 'full kpi']],
    ['analyze_breakdown', [
        'metrics' => ['avg_delivery_days', 'order_count'],
        'dimensions' => ['customer_state'],
        'filters' => [['field' => 'seller_city', 'value' => 'sao paulo']],
        'title' => 'exists fanout',
    ]],
    ['analyze_breakdown', [
        'metrics' => ['gmv'],
        'dimensions' => ['category'],
        'title' => 'full category',
    ]],
    ['analyze_top_per_group', [
        'partition_by' => 'seller_id',
        'rank_dimension' => 'category',
        'metrics' => ['gmv', 'order_count'],
        'extra_dimensions' => ['seller_state'],
        'top_n_per_group' => 1,
        'title' => 'full top per seller',
    ]],
];

foreach ($cases as [$tool, $args]) {
    $t0 = microtime(true);
    $r = DwhToolRegistry::dispatch($tool, $args);
    $ms = (int) round((microtime(true) - $t0) * 1000);
    $ok = (bool) ($r['ok'] ?? false);
    $full = $r['meta']['full_data_scan'] ?? null;
    $rows = $r['meta']['row_count'] ?? ($r['meta']['entities_ranked'] ?? '?');
    $strat = $r['meta']['join_meta']['fan_out_strategy'] ?? '-';
    echo sprintf(
        "%s ok=%s full=%s rows=%s ms=%d strategy=%s\n",
        $tool,
        json_encode($ok),
        json_encode($full),
        (string) $rows,
        $ms,
        (string) $strat
    );
    if (!$ok) {
        fwrite(STDERR, json_encode($r['errors'] ?? $r, JSON_UNESCAPED_UNICODE) . PHP_EOL);
        exit(1);
    }
    if ($full !== true && $tool !== 'analyze_kpi') {
        // kpi also sets full
    }
    if ($full !== true) {
        fwrite(STDERR, "FAIL: expected full_data_scan on {$tool}\n");
        exit(1);
    }
}

echo 'group_cap=' . FullDataContract::groupCap() . PHP_EOL;
echo "full-data large-scan OK\n";
