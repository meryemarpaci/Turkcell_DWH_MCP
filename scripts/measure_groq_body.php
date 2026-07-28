<?php
require_once dirname(__DIR__) . '/php/bootstrap.php';

$agent = new App\AgentOrchestrator(new App\GroqEngine());
$ref = new ReflectionClass($agent);
$build = $ref->getMethod('buildMessages');
$toolsM = $ref->getMethod('openaiTools');

// simulate state with one user message
$state = ['messages' => [['role' => 'user', 'text' => 'SP GMV', 'at' => date('c')]]];
$messages = $build->invoke($agent, $state);
$tools = $toolsM->invoke($agent);

$body = [
    'model' => app_env('GROQ_MODEL'),
    'messages' => $messages,
    'tools' => $tools,
];
$json = json_encode($body, JSON_UNESCAPED_UNICODE);
echo "model=" . app_env('GROQ_MODEL') . PHP_EOL;
echo "bytes=" . strlen($json) . PHP_EOL;
echo "msg_count=" . count($messages) . PHP_EOL;
foreach ($messages as $i => $m) {
    echo "msg$i role={$m['role']} content_len=" . strlen((string) ($m['content'] ?? '')) . PHP_EOL;
}
echo "tools=" . count($tools) . " tools_bytes=" . strlen(json_encode($tools)) . PHP_EOL;
// approx tokens ~ chars/4
echo "approx_tokens=" . (int) (strlen($json) / 3) . PHP_EOL;
