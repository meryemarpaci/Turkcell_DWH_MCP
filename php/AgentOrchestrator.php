<?php

declare(strict_types=1);

namespace App;

use App\Mcp\DwhToolRegistry;
use App\Mcp\McpClient;
use App\Discovery\QueryPlanner;
use App\Semantic\RegistryStore;
use App\Semantic\SchemaChecker;
use App\Tools\LlmPayload;
use App\Tools\ProbeTool;
use App\Tools\ReportTool;
use App\Tools\SchemaTool;
use App\Tools\SqlGuard;

final class AgentOrchestrator
{
    private SchemaTool $schemaTool;
    private ProbeTool $probeTool;
    private ReportTool $reportTool;
    private LlmEngine $llm;
    private SessionStore $sessions;
    private AgentLog $log;
    private McpClient $mcpClient;
    private bool $mcpLocalFallback = true;

    public function __construct(?LlmEngine $llm = null, ?callable $onProgress = null)
    {
        $guard = new SqlGuard(SemanticConfig::allowedTables());
        $this->schemaTool = new SchemaTool();
        $this->probeTool = new ProbeTool($guard);
        $this->reportTool = new ReportTool($guard);
        $this->llm = $llm ?? new GeminiEngine();
        $this->sessions = new SessionStore();
        $this->log = new AgentLog($onProgress);
        $mcpBase = app_env('MCP_ENDPOINT', 'http://localhost:8081/api/mcp');
        $mcpTimeout = (int) (app_env('MCP_TIMEOUT_SECONDS', '300') ?? 300);
        $this->mcpClient = new McpClient((string) $mcpBase, max(60, $mcpTimeout));
        $this->mcpLocalFallback = !in_array(
            strtolower((string) (app_env('MCP_LOCAL_FALLBACK', '1') ?? '1')),
            ['0', 'false', 'no', 'off'],
            true
        );
    }

    public function handle(string $sessionId, string $message): array
    {
        $this->log->add('orchestrator_start', [
            'provider' => $this->llm->name(),
            'session_id' => $sessionId,
            'user_message' => AgentLog::preview($message, 240),
        ]);

        $state = $this->sessions->load($sessionId);
        $datasetId = (string) ($state['dataset_id'] ?? DatasetProfile::id());
        if ($datasetId === '') {
            $datasetId = DatasetProfile::id();
        }
        $state['dataset_id'] = $datasetId;
        RegistryStore::setActiveDataset($datasetId);
        DwhToolRegistry::setActiveDataset($datasetId);

        // Cheap discovery bootstrap (profile + join graph if empty) — no LLM
        try {
            (new QueryPlanner())->bootstrap();
        } catch (\Throwable $e) {
            $this->log->add('discovery_bootstrap_error', ['error' => $e->getMessage()]);
        }

        $msgs = $state['messages'] ?? [];
        $last = $msgs !== [] ? $msgs[array_key_last($msgs)] : null;
        $already = is_array($last)
            && ($last['role'] ?? '') === 'user'
            && (string) ($last['text'] ?? '') === $message;
        if (!$already) {
            $state['messages'][] = ['role' => 'user', 'text' => $message, 'at' => date('c')];
        }

        $messages = $this->buildMessages($state);
        $tools = $this->openaiTools();
        $this->log->add('context_built', [
            'history_msgs' => max(0, count($messages) - 1),
            'system_chars' => AgentLog::bytes($messages[0]['content'] ?? ''),
            'tools' => array_map(static fn ($t) => $t['function']['name'] ?? '', $tools),
        ]);

        $collectedReports = [];
        $clarify = null;
        $trace = [];
        $maxSteps = 8;
        $maxReports = 5; // multiple analyze_* tools allowed
        $finalText = '';
        $llmCalls = 0;
        $emptyReportRetries = 0;
        $analysisTools = ['analyze_kpi', 'analyze_breakdown', 'analyze_top_per_group', 'analyze_trend'];

        for ($step = 0; $step < $maxSteps; $step++) {
            $this->log->add('llm_request', [
                'step' => $step,
                'provider' => $this->llm->name(),
                'with_tools' => true,
                'message_count' => count($messages),
            ]);
            $tCall = microtime(true);
            try {
                $response = $this->llm->complete($messages, $tools);
            } catch (\Throwable $e) {
                $this->log->add('llm_error', [
                    'step' => $step,
                    'provider' => $this->llm->name(),
                    'error' => $e->getMessage(),
                    'elapsed_ms' => (int) round((microtime(true) - $tCall) * 1000),
                ]);
                throw $e;
            }
            $llmCalls++;
            $toolCalls = $response['tool_calls'] ?? [];
            $content = $response['content'] ?? null;
            $this->log->add('llm_response', [
                'step' => $step,
                'provider' => $this->llm->name(),
                'elapsed_ms' => (int) round((microtime(true) - $tCall) * 1000),
                'has_text' => $content !== null && trim((string) $content) !== '',
                'text_preview' => AgentLog::preview((string) $content),
                'tool_calls' => array_map(static fn ($tc) => $tc['name'] ?? '?', $toolCalls),
            ]);

            if ($toolCalls === []) {
                $finalText = trim((string) ($content ?? ''));
                if ($finalText !== '') {
                    $messages[] = ['role' => 'assistant', 'content' => $finalText];
                }
                $this->log->add('final_text_from_model', ['chars' => mb_strlen($finalText)]);
                break;
            }

            $assistantWire = [
                'role' => 'assistant',
                'content' => $content,
                'tool_calls' => array_map(static function (array $tc): array {
                    $out = [
                        'id' => $tc['id'],
                        'type' => 'function',
                        'function' => [
                            'name' => $tc['name'],
                            'arguments' => json_encode($tc['arguments'], JSON_UNESCAPED_UNICODE),
                        ],
                    ];
                    if (!empty($tc['thought_signature'])) {
                        $out['thought_signature'] = $tc['thought_signature'];
                    }
                    return $out;
                }, $toolCalls),
            ];
            if (!empty($response['gemini_parts']) && is_array($response['gemini_parts'])) {
                $assistantWire['gemini_parts'] = $response['gemini_parts'];
            }
            $messages[] = $assistantWire;

            foreach ($toolCalls as $tc) {
                $name = $tc['name'];
                $args = is_array($tc['arguments'] ?? null) ? $tc['arguments'] : [];
                $this->log->add('tool_call', [
                    'tool' => $name,
                    'args' => $args,
                    'sql' => isset($args['sql']) ? (string) $args['sql'] : null,
                    'report_type' => isset($args['report_type']) ? (string) $args['report_type'] : null,
                    'title' => isset($args['title']) ? (string) $args['title'] : null,
                    'max_rows' => $args['max_rows'] ?? null,
                ]);
                $tTool = microtime(true);
                $result = $this->dispatchTool($name, $args);
                $compact = LlmPayload::compactToolResult($name, $result);
                $this->log->add('tool_result', [
                    'tool' => $name,
                    'ok' => $result['ok'] ?? null,
                    'elapsed_ms' => (int) round((microtime(true) - $tTool) * 1000),
                    'llm_payload_bytes' => AgentLog::bytes($compact),
                    'result_preview' => AgentLog::preview(json_encode($compact, JSON_UNESCAPED_UNICODE), 280),
                ]);
                $trace[] = ['tool' => $name, 'args' => $args, 'ok' => $result['ok'] ?? null];

                if ($name === 'ask_user' && $this->isResolvableWithoutClarify($message)) {
                    $this->log->add('ask_user_suppressed', [
                        'reason' => 'message already has geo/time/metric cues — resolve via DATA CALENDAR',
                        'user_message' => AgentLog::preview($message, 160),
                    ]);
                    $calendar = DataCalendar::info();
                    $defaults = DatasetProfile::defaults();
                    $aliasHint = '';
                    foreach (DatasetProfile::aliases() as $a) {
                        if ($aliasHint === '' && !empty($a['sql'])) {
                            $aliasHint = (string) $a['sql'];
                        }
                    }
                    $status = trim((string) ($defaults['status_filter_sql'] ?? ''));
                    $compact = [
                        'ok' => false,
                        'suppressed' => true,
                        'instruction' => 'Do NOT ask the user. Use DATA CALENDAR ranges and call analyze_kpi / analyze_breakdown / analyze_trend now '
                            . "(geçen ay = {$calendar['prev_month_start']}..{$calendar['prev_month_end']}; "
                            . "bu ay = {$calendar['latest_month_start']}..{$calendar['latest_month_end']}"
                            . ($aliasHint !== '' ? "; e.g. {$aliasHint}" : '')
                            . ($status !== '' ? "; prefer {$status}" : '')
                            . '). Do not probe unless join/filter is uncertain.',
                    ];
                } elseif ($name === 'ask_user') {
                    $clarify = $result;
                }
                if (in_array($name, $analysisTools, true) && ($result['ok'] ?? false)) {
                    $collectedReports[] = $result;
                    $rowCount = (int) ($result['meta']['row_count'] ?? count($result['table']['rows'] ?? []));
                    $kpiZero = true;
                    foreach ($result['kpi'] ?? [] as $k) {
                        if (isset($k['value']) && is_numeric($k['value']) && (float) $k['value'] != 0.0) {
                            $kpiZero = false;
                            break;
                        }
                    }
                    if ($emptyReportRetries < 1 && ($rowCount === 0 || ($kpiZero && ($result['kpi'] ?? []) !== []))) {
                        $emptyReportRetries++;
                        $cal = DataCalendar::info();
                        $defaults = DatasetProfile::defaults();
                        $status = trim((string) ($defaults['status_filter_sql'] ?? ''));
                        $compact['zero_result_hint'] = 'Query returned empty/zero. Retry analyze_* ONCE with '
                            . "date_from/date_to = geçen ay {$cal['prev_month_start']}..{$cal['prev_month_end']} "
                            . "or bu ay {$cal['latest_month_start']}..{$cal['latest_month_end']}"
                            . ($status !== '' ? ", keep default status" : '')
                            . '. Use profile aliases in filters.';
                        array_pop($collectedReports);
                        $this->log->add('empty_report_retry_hint', [
                            'hint' => $compact['zero_result_hint'],
                        ]);
                    } elseif (count($collectedReports) >= $maxReports) {
                        $compact['stop_hint'] = 'Analysis budget reached. Write the Turkish answer from tool summaries now.';
                        $this->log->add('reports_cap_reached', ['count' => count($collectedReports)]);
                    } else {
                        $compact['next_hint'] = 'Tool ran on full filtered data. Call another analyze_* if needed, else answer.';
                    }
                } elseif (!($result['ok'] ?? true)) {
                    $compact['retry_hint'] = $result['retry_hint']
                        ?? ((string) ($result['errors'][0] ?? 'Fix tool args using search_metrics and retry.'));
                    $this->log->add('tool_retry_hint', [
                        'tool' => $name,
                        'hint' => $compact['retry_hint'],
                    ]);
                }

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $tc['id'],
                    'name' => $name,
                    'content' => json_encode($compact, JSON_UNESCAPED_UNICODE),
                ];
            }

            if ($clarify !== null) {
                $finalText = trim((string) ($clarify['message'] ?? ''));
                $this->log->add('clarify_stop', [
                    'message_preview' => AgentLog::preview($finalText),
                    'questions' => $clarify['questions'] ?? [],
                ]);
                break;
            }

            // Allow iterative follow-up tool calls; stop early only at report cap
            if (count($collectedReports) >= $maxReports) {
                $this->log->add('reports_ready', [
                    'count' => count($collectedReports),
                    'ids' => array_map(static fn ($r) => $r['report_id'] ?? '?', $collectedReports),
                    'reason' => 'cap',
                ]);
                break;
            }
        }

        if ($clarify === null && $finalText === '') {
            if ($collectedReports !== []) {
                $this->log->add('compose_report_start', ['provider' => $this->llm->name()]);
                $t = microtime(true);
                $composed = $this->composeReport($message, $collectedReports);
                $finalText = $composed['text'];
                if ($composed['used_llm']) {
                    $llmCalls++;
                }
                $this->log->add('compose_report_done', [
                    'elapsed_ms' => (int) round((microtime(true) - $t) * 1000),
                    'used_llm' => $composed['used_llm'],
                    'repaired' => $composed['repaired'] ?? false,
                    'text_preview' => AgentLog::preview($finalText, 320),
                ]);
            } else {
                $this->log->add('request_final_answer_start', ['provider' => $this->llm->name()]);
                $t = microtime(true);
                $finalText = $this->requestFinalAnswer($messages, $collectedReports);
                $llmCalls++;
                $this->log->add('request_final_answer_done', [
                    'elapsed_ms' => (int) round((microtime(true) - $t) * 1000),
                    'text_preview' => AgentLog::preview($finalText),
                ]);
            }
        } elseif ($clarify !== null && $finalText === '') {
            $this->log->add('clarify_empty_message_retry', []);
            $finalText = $this->requestFinalAnswer($messages, []);
            $llmCalls++;
        }

        $type = $clarify !== null ? 'clarify' : ($collectedReports !== [] ? 'report' : 'assistant');

        $state['messages'][] = [
            'role' => 'assistant',
            'type' => $type,
            'text' => LlmPayload::trimHistoryText($finalText, 500),
            'clarify' => $clarify,
            'at' => date('c'),
        ];
        $state['reports'] = array_merge($state['reports'] ?? [], $collectedReports);
        $state['pending_clarify'] = $clarify;
        $this->sessions->save($sessionId, $state);

        $uiReports = array_map(static function (array $r): array {
            $type = strtolower((string) ($r['report_type'] ?? 'table'));
            $uiCap = match ($type) {
                'browse' => 100,
                'trend' => 24,
                'kpi' => 8,
                default => 40,
            };
            if (isset($r['table']['rows']) && is_array($r['table']['rows'])) {
                $r['table']['rows'] = array_slice($r['table']['rows'], 0, $uiCap);
            }
            if (isset($r['series']) && is_array($r['series'])) {
                $r['series'] = array_slice($r['series'], 0, 2);
            }
            return $r;
        }, $collectedReports);

        $this->log->add('orchestrator_done', [
            'type' => $type,
            'llm_calls' => $llmCalls,
            'provider' => $this->llm->name(),
            'report_count' => count($uiReports),
        ]);

        return [
            'ok' => true,
            'session_id' => $sessionId,
            'type' => $type,
            'message' => $finalText,
            'clarify' => $clarify,
            'reports' => $uiReports,
            'trace' => $trace,
            'provider' => $this->llm->name(),
            'llm_calls' => $llmCalls,
            'logs' => $this->log->all(),
        ];
    }

    private function buildSystemPrompt(): string
    {
        $base = (string) file_get_contents(APP_ROOT . '/php/prompts/system.md');
        $fragment = trim((string) (DatasetProfile::prompt()['system_fragment'] ?? ''));
        $schema = $this->schemaTool->schemaPromptBlock(true);
        $calendar = DataCalendar::contextBlock();
        $parts = [$base];
        if ($fragment !== '') {
            $parts[] = "# DATASET PROFILE\nid: " . DatasetProfile::id()
                . "\n" . (DatasetProfile::get()['display_name'] ?? '')
                . "\n\n" . $fragment;
        }
        $parts[] = $calendar;
        $parts[] = $schema;

        // Cheap schema-check (no LLM): surface new unregistered columns only.
        try {
            $check = (new SchemaChecker())->check(RegistryStore::datasetId());
            $note = trim((string) ($check['prompt_note'] ?? ''));
            if ($note !== '') {
                $parts[] = "# SCHEMA CHECK\n" . $note;
            }
            $this->log->add('schema_check', [
                'dataset_id' => $check['dataset_id'] ?? null,
                'changed' => $check['changed'] ?? false,
                'unregistered' => count($check['unregistered_columns'] ?? []),
            ]);
        } catch (\Throwable $e) {
            $this->log->add('schema_check_error', ['error' => $e->getMessage()]);
        }

        return implode("\n\n", $parts);
    }

    /** @return list<array<string,mixed>> */
    private function buildMessages(array $state): array
    {
        $messages = [
            ['role' => 'system', 'content' => $this->buildSystemPrompt()],
        ];
        $history = array_slice($state['messages'] ?? [], -6);
        foreach ($history as $m) {
            $text = (string) ($m['text'] ?? $m['message'] ?? '');
            if ($text === '') {
                continue;
            }
            $role = ($m['role'] ?? '') === 'user' ? 'user' : 'assistant';
            $messages[] = [
                'role' => $role,
                'content' => LlmPayload::trimHistoryText($text, $role === 'assistant' ? 400 : 800),
            ];
        }
        return $messages;
    }

    /**
     * @param list<array<string,mixed>> $messages
     * @param list<array<string,mixed>> $reports
     */
    private function requestFinalAnswer(array $messages, array $reports): string
    {
        $nudge = [
            'role' => 'user',
            'content' => 'Yukarıdaki araç özetlerine göre Türkçe, kısa yanıt yaz. '
                . 'Sadece tool sonucundaki KPI/özeti kullan; ham tablo uydurma.',
        ];
        if ($reports !== []) {
            $nudge['content'] .= "\n\nRAPOR_JSON:\n"
                . json_encode(LlmPayload::compactReportsForPrompt($reports), JSON_UNESCAPED_UNICODE);
        }
        $messages[] = $nudge;
        $response = $this->llm->complete($messages, []);
        return trim((string) ($response['content'] ?? ''));
    }

    /**
     * @param list<array<string,mixed>> $reports
     * @return array{text:string,used_llm:bool}
     */
    private function composeReport(string $userQuestion, array $reports): array
    {
        $allUiOnly = $reports !== [] && array_reduce(
            $reports,
            static function (bool $ok, array $r): bool {
                $type = strtolower((string) ($r['report_type'] ?? ''));
                $delivery = (string) ($r['delivery'] ?? '');
                return $ok && ($type === 'browse' || $delivery === 'ui_only');
            },
            true
        );
        if ($allUiOnly) {
            $parts = [];
            foreach ($reports as $r) {
                $n = (int) ($r['meta']['row_count'] ?? count($r['table']['rows'] ?? []));
                $title = (string) ($r['title'] ?? 'Liste');
                $trunc = !empty($r['meta']['truncated']) ? ' (limit uygulandı)' : '';
                $parts[] = "{$title}: {$n} satır UI tablosunda gösteriliyor{$trunc}.";
            }
            return [
                'text' => implode(' ', $parts) . ' Satır değerlerini uydurmadım; detay aşağıdaki tabloda.',
                'used_llm' => false,
            ];
        }

        $reportPrompt = (string) file_get_contents(APP_ROOT . '/php/prompts/report.md');
        $payload = json_encode(
            [
                'question' => $userQuestion,
                'reports' => LlmPayload::compactReportsForPrompt($reports),
            ],
            JSON_UNESCAPED_UNICODE
        );
        $response = $this->llm->complete(
            [
                [
                    'role' => 'system',
                    'content' => 'Short Turkish BI note. Max 8 complete lines. '
                        . 'Always finish every sentence with . ! or ? — never stop mid-phrase. '
                        . 'No ### headings, no markdown tables.',
                ],
                ['role' => 'user', 'content' => $reportPrompt . "\n\nDATA:\n" . $payload],
            ],
            []
        );
        $text = trim((string) ($response['content'] ?? ''));
        $repaired = false;
        if ($text === '' || $this->looksTruncated($text)) {
            $fallback = $this->composeDeterministic($reports, $userQuestion);
            // Prefer LLM if it already has a usable paragraph; only replace empty/mid-cut.
            if ($text === '' || mb_strlen($text) < 60) {
                $text = $fallback;
            } else {
                // Mid-cut: keep start, finish with deterministic closing line
                $text = rtrim($text, " \t\n\r-,;") . ".\n" . $fallback;
            }
            $repaired = true;
        }
        return [
            'text' => $text,
            'used_llm' => true,
            'repaired' => $repaired,
        ];
    }

    private function looksTruncated(string $text): bool
    {
        $t = rtrim($text);
        if ($t === '') {
            return true;
        }
        // Clear mid-phrase cuts (the original bug)
        if (preg_match('/\b(seviyesinden|seviyesine|karşılık)$/iu', $t)) {
            return true;
        }
        if (preg_match('/[-,;:]$/u', $t)) {
            return true;
        }
        // Very short answer without terminal punctuation
        if (mb_strlen($t) < 50 && !preg_match('/[.!?…]$/u', $t)) {
            return true;
        }
        return false;
    }

    /** @param list<array<string,mixed>> $reports */
    private function composeDeterministic(array $reports, string $question): string
    {
        $parts = [];
        foreach ($reports as $r) {
            $title = (string) ($r['title'] ?? 'Rapor');
            $type = strtolower((string) ($r['report_type'] ?? 'table'));
            $compact = LlmPayload::analysisResultForLlm($r);

            if ($type === 'browse' || ($compact['delivery'] ?? '') === 'ui_only') {
                $n = (int) ($compact['meta']['row_count'] ?? 0);
                $parts[] = "{$title} için {$n} satır aşağıdaki tabloda listeleniyor.";
                continue;
            }

            $series = $compact['series_summary'] ?? [];
            if ($series !== []) {
                $bits = [];
                foreach ($series as $s) {
                    $name = $this->humanMetric((string) ($s['name'] ?? 'metrik'));
                    $first = $s['first'] ?? [];
                    $last = $s['last'] ?? [];
                    $delta = $s['delta_pct'] ?? null;
                    $fx = (string) ($first['x'] ?? '');
                    $lx = (string) ($last['x'] ?? '');
                    $line = sprintf(
                        '%s %s döneminde %s iken %s döneminde %s',
                        $name,
                        $fx,
                        $this->fmtNum($first['y'] ?? null),
                        $lx,
                        $this->fmtNum($last['y'] ?? null)
                    );
                    if (is_numeric($delta)) {
                        $d = (float) $delta;
                        $dir = $d > 0.5 ? 'yükseldi' : ($d < -0.5 ? 'geriledi' : 'yatay kaldı');
                        $line .= sprintf(' (%s, %%%s)', $dir, $this->fmtNum(abs($d)));
                    }
                    $bits[] = $line;
                }
                $intro = $title !== '' ? "{$title}: " : '';
                $parts[] = $intro . implode('; ', $bits) . '.';
                continue;
            }

            $kpi = $compact['kpi'] ?? [];
            if ($kpi !== []) {
                $bits = [];
                foreach (array_slice($kpi, 0, 4) as $k) {
                    $bits[] = $this->humanMetric((string) ($k['name'] ?? 'metrik'))
                        . ' ' . $this->fmtNum($k['value'] ?? null);
                }
                $parts[] = $title . ' özeti — ' . implode(', ', $bits) . '.';
            }
        }

        if ($parts === []) {
            return 'Analiz tamamlandı; özet grafik ve tabloda.';
        }
        $parts[] = 'Ayrıntılar aşağıdaki görsellerde.';
        return implode(' ', $parts);
    }

    private function humanMetric(string $name): string
    {
        $n = mb_strtolower(trim($name));
        return match (true) {
            str_contains($n, 'gmv') || str_contains($n, 'price') || str_contains($n, 'revenue') => 'GMV',
            str_contains($n, 'order') => 'sipariş adedi',
            str_contains($n, 'customer') => 'müşteri sayısı',
            default => $name,
        };
    }

    private function fmtNum(mixed $v): string
    {
        if ($v === null || $v === '') {
            return '—';
        }
        if (!is_numeric($v)) {
            return (string) $v;
        }
        $n = (float) $v;
        if (abs($n - round($n)) < 0.001) {
            return number_format($n, 0, ',', '.');
        }
        return number_format($n, 2, ',', '.');
    }

    /** @return list<array<string,mixed>> */
    private function openaiTools(): array
    {
        $decls = $this->toolDeclarations();
        $out = [];
        foreach ($decls as $d) {
            $out[] = [
                'type' => 'function',
                'function' => [
                    'name' => $d['name'],
                    'description' => $d['description'],
                    'parameters' => $d['parameters'],
                ],
            ];
        }
        return $out;
    }

    private function toolDeclarations(): array
    {
        return DwhToolRegistry::toolSchemas();
    }

    private function dispatchTool(string $name, array $args): array
    {
        $t = microtime(true);
        $endpoint = app_env('MCP_ENDPOINT', 'http://localhost:8081/api/mcp');
        if (!isset($args['dataset_id']) || trim((string) $args['dataset_id']) === '') {
            $args['dataset_id'] = RegistryStore::datasetId();
        }
        $this->log->add('mcp_request', [
            'tool' => $name,
            'endpoint' => $endpoint,
            'dataset_id' => $args['dataset_id'],
            'args_keys' => array_keys($args),
        ]);
        try {
            $result = $this->mcpClient->callTool($name, $args);
            $this->log->add('mcp_response', [
                'tool' => $name,
                'ok' => $result['ok'] ?? true,
                'elapsed_ms' => (int) round((microtime(true) - $t) * 1000),
                'via' => 'http',
            ]);
            return $result;
        } catch (\RuntimeException $e) {
            $this->log->add('mcp_error', [
                'tool' => $name,
                'error' => $e->getMessage(),
                'elapsed_ms' => (int) round((microtime(true) - $t) * 1000),
            ]);
            // Transport-only fallback (MCP down). Prefer fixing SQL/time limits so HTTP succeeds.
            if ($this->mcpLocalFallback && $this->isTransportFailure($e->getMessage())) {
                $t2 = microtime(true);
                try {
                    $result = DwhToolRegistry::dispatch($name, $args);
                    $this->log->add('mcp_local_fallback', [
                        'tool' => $name,
                        'ok' => $result['ok'] ?? true,
                        'elapsed_ms' => (int) round((microtime(true) - $t2) * 1000),
                        'reason' => $e->getMessage(),
                    ]);
                    return $result;
                } catch (\Throwable $inner) {
                    return [
                        'ok' => false,
                        'errors' => [
                            $e->getMessage(),
                            'Local fallback failed: ' . $inner->getMessage(),
                        ],
                        'retry_hint' => 'MCP server unreachable — ensure `php -S localhost:8081 mcp_router.php` is running, or rely on local fallback.',
                    ];
                }
            }
            return [
                'ok' => false,
                'errors' => [$e->getMessage()],
                'retry_hint' => 'MCP transport failed. Retry analyze_* once; if it persists, restart MCP on :8081.',
            ];
        }
    }

    private function isTransportFailure(string $message): bool
    {
        $m = strtolower($message);
        return str_contains($m, 'could not reach')
            || str_contains($m, 'transport error')
            || str_contains($m, 'invalid json')
            || str_contains($m, 'failed to open stream')
            || str_contains($m, 'connection refused')
            || str_contains($m, 'timed out');
    }

    /** True when the user already gave enough cues to analyze without asking. */
    private function isResolvableWithoutClarify(string $message): bool
    {
        $m = mb_strtolower($message);
        $cues = DatasetProfile::clarifyCues();
        $geoPat = trim((string) ($cues['geo_pattern'] ?? ''));
        $timePat = trim((string) ($cues['time_pattern'] ?? ''));
        $metricPat = trim((string) ($cues['metric_pattern'] ?? ''));

        $geo = false;
        if ($geoPat !== '') {
            $geo = @preg_match('/' . $geoPat . '/ui', $m) === 1;
        }
        // Also match profile aliases
        if (!$geo) {
            foreach (DatasetProfile::aliases() as $a) {
                foreach ($a['patterns'] ?? [] as $pat) {
                    $pat = (string) $pat;
                    if ($pat !== '' && @preg_match('/' . $pat . '/ui', $m) === 1) {
                        $geo = true;
                        break 2;
                    }
                }
            }
        }
        $time = $timePat !== '' && @preg_match('/' . $timePat . '/ui', $m) === 1;
        $metric = $metricPat !== '' && @preg_match('/' . $metricPat . '/ui', $m) === 1;
        $score = (int) $geo + (int) $time + (int) $metric;
        return $score >= 2 || ($metric && mb_strlen(trim($message)) >= 20);
    }
}
