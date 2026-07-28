<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/php/bootstrap.php';
@set_time_limit(180);

use App\GeminiEngine;

$engine = new GeminiEngine();
echo "engine=" . $engine->name() . PHP_EOL;

$tools = [[
    'type' => 'function',
    'function' => [
        'name' => 'probe_join',
        'description' => 'Validate join ids',
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'join_ids' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['join_ids'],
        ],
    ],
]];

$messages = [
    ['role' => 'system', 'content' => 'Call probe_join with join_ids=["orders_customer"]. Do not answer in text.'],
    ['role' => 'user', 'content' => 'Probe the join now.'],
];

echo "call1...\n";
$r1 = $engine->complete($messages, $tools);
echo "tool_calls=" . count($r1['tool_calls']) . PHP_EOL;
echo "has_gemini_parts=" . (!empty($r1['gemini_parts']) ? '1' : '0') . PHP_EOL;
$sig = $r1['tool_calls'][0]['thought_signature'] ?? null;
echo "sig_len=" . (is_string($sig) ? strlen($sig) : 0) . PHP_EOL;

if (($r1['tool_calls'] ?? []) === []) {
    echo "FAIL: no tool call\n";
    exit(1);
}

$messages[] = [
    'role' => 'assistant',
    'content' => $r1['content'],
    'tool_calls' => array_map(static function (array $tc): array {
        $o = [
            'id' => $tc['id'],
            'type' => 'function',
            'function' => [
                'name' => $tc['name'],
                'arguments' => json_encode($tc['arguments']),
            ],
        ];
        if (!empty($tc['thought_signature'])) {
            $o['thought_signature'] = $tc['thought_signature'];
        }
        return $o;
    }, $r1['tool_calls']),
    'gemini_parts' => $r1['gemini_parts'],
];
$messages[] = [
    'role' => 'tool',
    'tool_call_id' => $r1['tool_calls'][0]['id'],
    'name' => $r1['tool_calls'][0]['name'],
    'content' => json_encode(['ok' => true, 'row_count' => 1, 'sample' => [['x' => 1]]]),
];

echo "call2 (echo thoughtSignature)...\n";
try {
    $r2 = $engine->complete($messages, $tools);
    echo "OK call2 content_preview=" . substr((string) ($r2['content'] ?? ''), 0, 120) . PHP_EOL;
    echo "OK call2 tools=" . implode(',', array_map(static fn ($t) => $t['name'], $r2['tool_calls'] ?? [])) . PHP_EOL;
} catch (Throwable $e) {
    echo "FAIL call2: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
