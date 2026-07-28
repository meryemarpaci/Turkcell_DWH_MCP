<?php
require_once dirname(__DIR__) . '/php/bootstrap.php';
$r = new ReflectionClass(App\GroqEngine::class);
$m = $r->getMethod('parseXmlToolCalls');
$m->setAccessible(true);
$sample = '<function=propose_tables {"intent_hint": "SP eyaletinde aylik siparis adedi ve GMV trendi"} </function>';
var_export($m->invoke(null, $sample));
echo PHP_EOL;
