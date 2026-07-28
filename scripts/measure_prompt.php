<?php
require_once dirname(__DIR__) . '/php/bootstrap.php';

$s = new App\Tools\SchemaTool();
$schema = $s->schemaPromptBlock(true);
$sys = file_get_contents(dirname(__DIR__) . '/php/prompts/system.md');
echo 'schema_chars=' . strlen($schema) . PHP_EOL;
echo 'sys_chars=' . strlen($sys) . PHP_EOL;

$o = new App\AgentOrchestrator(new App\GroqEngine());
$ref = new ReflectionClass($o);
$m = $ref->getMethod('openaiTools');
$tools = $m->invoke($o);
echo 'tools_json_chars=' . strlen(json_encode($tools)) . PHP_EOL;
echo 'tools_count=' . count($tools) . PHP_EOL;
echo 'provider=' . (new App\GroqEngine())->name() . PHP_EOL;
echo 'total_est_chars=' . (strlen($sys) + strlen($schema) + strlen(json_encode($tools))) . PHP_EOL;
