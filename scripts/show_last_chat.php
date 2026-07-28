<?php
$j = json_decode(file_get_contents(__DIR__ . '/../storage/runtime/last_chat.json'), true);
echo 'ok=' . (($j['ok'] ?? false) ? '1' : '0') . ' provider=' . ($j['provider'] ?? '') . ' type=' . ($j['type'] ?? '') . PHP_EOL;
if (!empty($j['errors'])) {
    foreach ($j['errors'] as $e) {
        echo 'ERR=' . substr($e, 0, 250) . PHP_EOL;
    }
}
foreach ($j['logs'] ?? [] as $e) {
    echo $e['t_ms'] . ' ' . $e['stage'];
    if (!empty($e['error'])) {
        echo ' ERR=' . substr($e['error'], 0, 140);
    }
    if (!empty($e['tool_calls'])) {
        echo ' tools=' . implode(',', $e['tool_calls']);
    }
    if (!empty($e['tool'])) {
        echo ' tool=' . $e['tool'];
    }
    echo PHP_EOL;
}
echo 'msg=' . substr($j['message'] ?? '', 0, 220) . PHP_EOL;
