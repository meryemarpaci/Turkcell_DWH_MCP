<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/php/bootstrap.php';

use App\Mcp\DwhToolRegistry;
use App\Tools\AnalyticsTool;
use App\Tools\ReportTool;
use App\Tools\SqlGuard;
use App\SemanticConfig;

$guard = new SqlGuard(SemanticConfig::allowedTables());
$analytics = new AnalyticsTool(new ReportTool($guard));

$t0 = microtime(true);
$r = $analytics->analyzeBreakdown(
    ['avg_delivery_days', 'order_count'],
    ['customer_state'],
    null,
    null,
    ['seller_city' => 'sao paulo'],
    true,
    null,
    'SP sellers by customer state'
);
$ms = (int) round((microtime(true) - $t0) * 1000);

echo 'ok=' . json_encode($r['ok'] ?? false)
    . ' ms=' . $ms
    . ' rows=' . ($r['meta']['row_count'] ?? 0)
    . ' strategy=' . ($r['meta']['join_meta']['fan_out_strategy'] ?? ($r['join_meta']['fan_out_strategy'] ?? '?'))
    . PHP_EOL;

if (!($r['ok'] ?? false)) {
    fwrite(STDERR, json_encode($r, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL);
    exit(1);
}

$sql = (string) ($r['sql_used'] ?? $r['meta']['sql_used'] ?? '');
// attachMeta may put sql elsewhere — rebuild peek
$built = (new ReflectionClass($analytics));
echo 'semi=' . json_encode($r['meta']['semi_join_filters'] ?? $r['join_meta']['semi_join_filters'] ?? null) . PHP_EOL;

// Direct SQL check via dispatch meta
$via = DwhToolRegistry::dispatch('analyze_breakdown', [
    'metrics' => ['avg_delivery_days', 'order_count'],
    'dimensions' => ['customer_state'],
    'filters' => [['field' => 'seller_city', 'value' => 'sao paulo']],
    'title' => 'mcp path',
]);
echo 'mcp_dispatch ok=' . json_encode($via['ok'] ?? false)
    . ' rows=' . ($via['meta']['row_count'] ?? 0)
    . ' join_meta=' . json_encode($via['meta']['join_meta'] ?? $via['join_meta'] ?? null, JSON_UNESCAPED_UNICODE)
    . PHP_EOL;

if ($ms > 15000) {
    fwrite(STDERR, "WARN: still slow ({$ms}ms) — expected EXISTS semi-join <5s\n");
}

if (($via['meta']['row_count'] ?? 0) < 5) {
    fwrite(STDERR, "WARN: expected many customer states for SP sellers, got "
        . ($via['meta']['row_count'] ?? 0) . "\n");
}

echo "fanout-safe breakdown OK\n";
