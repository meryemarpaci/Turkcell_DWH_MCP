<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/php/bootstrap.php';

use App\Mcp\DwhToolRegistry;
use App\Semantic\RegistryService;
use App\Semantic\SchemaChecker;
use App\Tools\LlmPayload;

$r = new RegistryService();
$r->ensureSeededFromProfile();

$s = $r->search('seller', 10);
echo 'search dims=' . implode(',', array_column($s['dimensions'], 'dimension_id')) . PHP_EOL;

$c1 = (new SchemaChecker())->check();
$c2 = (new SchemaChecker())->check();
echo 'schema1 note_empty=' . json_encode(($c1['prompt_note'] ?? '') === '')
    . ' changed=' . json_encode($c1['changed'] ?? null) . PHP_EOL;
echo 'schema2 note_empty=' . json_encode(($c2['prompt_note'] ?? '') === '') . PHP_EOL;

$reg = DwhToolRegistry::dispatch('register_metric', [
    'metric_id' => 'test_probe_sum',
    'expression' => 'SUM(1)',
    'description' => 'probe',
]);
echo 'register ok=' . json_encode($reg['ok'] ?? false) . ' v=' . ($reg['version'] ?? '?') . PHP_EOL;

$d = DwhToolRegistry::dispatch('describe_column', [
    'table' => 'fact_order_items',
    'column' => 'price',
]);
echo 'describe ok=' . json_encode($d['ok'] ?? false)
    . ' kind=' . ($d['suggestion']['kind'] ?? '?')
    . PHP_EOL;

$br = DwhToolRegistry::dispatch('analyze_breakdown', [
    'dimensions' => ['customer_state'],
    'metrics' => ['gmv'],
]);
$compact = LlmPayload::compactToolResult('analyze_breakdown', $br);
echo 'llm mode=' . ($compact['table']['mode'] ?? '?')
    . ' and_more=' . ($compact['table']['and_more'] ?? '')
    . PHP_EOL;

$search = DwhToolRegistry::dispatch('search_metrics', ['query' => 'gmv']);
echo 'search_metrics ok=' . json_encode($search['ok'] ?? false)
    . ' n=' . count($search['metrics'] ?? [])
    . PHP_EOL;

echo "registry smoke OK\n";
