<?php

declare(strict_types=1);

namespace App;

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

    public function __construct(?LlmEngine $llm = null)
    {
        $guard = new SqlGuard(SemanticConfig::allowedTables());
        $this->schemaTool = new SchemaTool();
        $this->probeTool = new ProbeTool($guard);
        $this->reportTool = new ReportTool($guard);
        $this->llm = $llm ?? new GeminiEngine();
        $this->sessions = new SessionStore();
        $this->log = new AgentLog();
    }

    public function handle(string $sessionId, string $message): array
    {
        $this->log->add('orchestrator_start', [
            'provider' => $this->llm->name(),
            'session_id' => $sessionId,
            'user_message' => AgentLog::preview($message, 240),
        ]);

        $state = $this->sessions->load($sessionId);
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
        $maxSteps = 5;
        $finalText = '';
        $llmCalls = 0;

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
                    $compact = [
                        'ok' => false,
                        'suppressed' => true,
                            'instruction' => 'Do NOT ask the user. Use DATA CALENDAR relative ranges '
                                . "(geçen ay = {$calendar['prev_month_start']}..{$calendar['prev_month_end']}; "
                                . "bu ay = {$calendar['latest_month_start']}..{$calendar['latest_month_end']}; "
                                . "São Paulo = customer_state SP; prefer order_status=delivered). "
                                . 'Call probe_join then run_report now. Never use sparse Sep/Oct 2018 tail for "geçen ay".',
                    ];
                } elseif ($name === 'ask_user') {
                    $clarify = $result;
                }
                if ($name === 'run_report' && ($result['ok'] ?? false)) {
                    $collectedReports[] = $result;
                    $rowCount = (int) ($result['meta']['row_count'] ?? count($result['table']['rows'] ?? []));
                    $kpiZero = true;
                    foreach ($result['kpi'] ?? [] as $k) {
                        if (isset($k['value']) && is_numeric($k['value']) && (float) $k['value'] != 0.0) {
                            $kpiZero = false;
                            break;
                        }
                    }
                    if ($rowCount === 0 || ($kpiZero && ($result['kpi'] ?? []) !== [])) {
                        $cal = DataCalendar::info();
                        $compact['zero_result_hint'] = 'Query returned empty/zero. Retry run_report with '
                            . "geçen ay = {$cal['prev_month_start']}..{$cal['prev_month_end']} "
                            . "or bu ay = {$cal['latest_month_start']}..{$cal['latest_month_end']}, "
                            . 'customer_state=SP for São Paulo, and preferably order_status=delivered. '
                            . 'Do not conclude "no sales" until you retry once with DATA CALENDAR ranges.';
                        // allow another tool loop instead of stopping on empty report
                        array_pop($collectedReports);
                        $this->log->add('empty_report_retry_hint', [
                            'hint' => $compact['zero_result_hint'],
                        ]);
                    }
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

            if ($collectedReports !== []) {
                $this->log->add('reports_ready', [
                    'count' => count($collectedReports),
                    'ids' => array_map(static fn ($r) => $r['report_id'] ?? '?', $collectedReports),
                ]);
                break;
            }
        }

        if ($clarify === null && $finalText === '') {
            if ($collectedReports !== []) {
                $this->log->add('compose_report_start', ['provider' => $this->llm->name()]);
                $t = microtime(true);
                $finalText = $this->composeReport($message, $collectedReports);
                $llmCalls++;
                $this->log->add('compose_report_done', [
                    'elapsed_ms' => (int) round((microtime(true) - $t) * 1000),
                    'text_preview' => AgentLog::preview($finalText),
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
            if (isset($r['table']['rows']) && is_array($r['table']['rows'])) {
                $r['table']['rows'] = array_slice($r['table']['rows'], 0, 8);
            }
            if (isset($r['series']) && is_array($r['series'])) {
                // Prefer at most 2 series for chart clarity (orders + gmv)
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
        $schema = $this->schemaTool->schemaPromptBlock(true);
        $calendar = DataCalendar::contextBlock();
        return $base . "\n\n" . $calendar . "\n\n" . $schema;
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
            'content' => 'Yukarıdaki araç sonuçlarına dayanarak kullanıcıya Türkçe, kısa yanıt yaz. '
                . 'Ham tablo dökümü isteme/yazma; yalnızca KPI ve örnek satırları kullan.',
        ];
        if ($reports !== []) {
            $nudge['content'] .= "\n\nRAPOR_JSON:\n"
                . json_encode(LlmPayload::compactReportsForPrompt($reports), JSON_UNESCAPED_UNICODE);
        }
        $messages[] = $nudge;
        $response = $this->llm->complete($messages, []);
        return trim((string) ($response['content'] ?? ''));
    }

    private function composeReport(string $userQuestion, array $reports): string
    {
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
                ['role' => 'system', 'content' => 'Short Turkish BI note. Max 8 lines. No ### headings, no markdown tables.'],
                ['role' => 'user', 'content' => $reportPrompt . "\n\nDATA:\n" . $payload],
            ],
            []
        );
        return trim((string) ($response['content'] ?? ''));
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
        return [
            [
                'name' => 'list_schema',
                'description' => 'List DWH tables/columns (optional subset). Prefer using boot schema; call if need refresh.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'tables' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Optional table name filter',
                        ],
                    ],
                ],
            ],
            [
                'name' => 'propose_tables',
                'description' => 'Heuristic table suggestions from intent text',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'intent_hint' => ['type' => 'string'],
                    ],
                    'required' => ['intent_hint'],
                ],
            ],
            [
                'name' => 'probe_join',
                'description' => 'Validate join plan with COUNT/sample. Pass join edge ids. If fail, fix and retry.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'join_ids' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                        ],
                        'sql' => [
                            'type' => 'string',
                            'description' => 'Optional custom probe SQL instead of join_ids builder',
                        ],
                    ],
                ],
            ],
            [
                'name' => 'probe_filter',
                'description' => 'Validate filtered SELECT via COUNT + sample. If 0 rows, fix filters.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'sql' => ['type' => 'string'],
                    ],
                    'required' => ['sql'],
                ],
            ],
            [
                'name' => 'run_report',
                'description' => 'Run aggregate/report SQL in DB. Returns KPIs + at most 5 sample rows to the model (not full data).',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'sql' => ['type' => 'string'],
                        'report_type' => [
                            'type' => 'string',
                            'description' => 'kpi|trend|table|distribution|compare',
                        ],
                        'report_id' => ['type' => 'string'],
                        'title' => ['type' => 'string'],
                        'max_rows' => ['type' => 'integer', 'description' => 'Cap for SQL fetch before KPI; LLM still sees ≤5 rows'],
                    ],
                    'required' => ['sql'],
                ],
            ],
            [
                'name' => 'execute_query',
                'description' => 'Tiny SELECT sample only (hard max 5 rows). Prefer run_report for analytics.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'sql' => ['type' => 'string'],
                        'max_rows' => ['type' => 'integer', 'description' => 'Max 5'],
                    ],
                    'required' => ['sql'],
                ],
            ],
            [
                'name' => 'ask_user',
                'description' => 'RARE. Only if the user message has no analyzable intent (greeting only). '
                    . 'Do NOT use for date/state/category when the user already implied them '
                    . '(e.g. São Paulo + geçen ay + satışlar). Resolve via DATA CALENDAR instead.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'message' => ['type' => 'string'],
                        'questions' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                        ],
                        'filter_suggestions' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'field' => ['type' => 'string'],
                                    'label' => ['type' => 'string'],
                                    'example' => ['type' => 'string'],
                                ],
                            ],
                        ],
                    ],
                    'required' => ['message'],
                ],
            ],
        ];
    }

    private function dispatchTool(string $name, array $args): array
    {
        return match ($name) {
            'list_schema' => $this->schemaTool->listSchema(
                isset($args['tables']) && is_array($args['tables']) ? $args['tables'] : null
            ),
            'propose_tables' => $this->schemaTool->proposeTables((string) ($args['intent_hint'] ?? '')),
            'probe_join' => $this->probeTool->probeJoin(
                isset($args['join_ids']) && is_array($args['join_ids']) ? $args['join_ids'] : [],
                isset($args['sql']) ? (string) $args['sql'] : null
            ),
            'probe_filter' => $this->probeTool->probeFilter((string) ($args['sql'] ?? '')),
            'run_report' => $this->reportTool->runReport(
                (string) ($args['sql'] ?? ''),
                (string) ($args['report_type'] ?? 'table'),
                (string) ($args['report_id'] ?? 'report'),
                (string) ($args['title'] ?? 'Report'),
                isset($args['max_rows']) ? min(40, (int) $args['max_rows']) : 40
            ),
            'execute_query' => $this->reportTool->executeQuery(
                (string) ($args['sql'] ?? ''),
                isset($args['max_rows']) ? min(5, (int) $args['max_rows']) : 5
            ),
            'ask_user' => [
                'ok' => true,
                'message' => (string) ($args['message'] ?? ''),
                'questions' => $args['questions'] ?? [],
                'filter_suggestions' => $args['filter_suggestions'] ?? SemanticConfig::all()['filter_hints'] ?? [],
            ],
            default => ['ok' => false, 'errors' => ["Unknown tool: $name"]],
        };
    }

    /** True when the user already gave enough cues to analyze without asking. */
    private function isResolvableWithoutClarify(string $message): bool
    {
        $m = mb_strtolower($message);
        $geo = (bool) preg_match(
            '/\b(sp|rj|mg|pr|rs|ba|pe|ce|df|es|go|sc|são\s*paulo|sao\s*paulo|rio|eyalet|şehir|sehir)\b/u',
            $m
        );
        $time = (bool) preg_match(
            '/(geçen\s*ay|gecen\s*ay|bu\s*ay|bu\s*yıl|bu\s*yil|geçen\s*yıl|201[6-8]|'
            . 'ocak|şubat|subat|mart|nisan|mayıs|mayis|haziran|temmuz|ağustos|agustos|'
            . 'eylül|eylul|ekim|kasım|kasim|aralık|aralik|ayki|tarih|yılı|yili)/u',
            $m
        );
        $metric = (bool) preg_match(
            '/(satış|satis|gmv|ciro|sipariş|siparis|ortalama|analiz|trend|kategori|'
            . 'health|beauty|ürün|urun|müşteri|musteri|performans)/u',
            $m
        );
        $score = (int) $geo + (int) $time + (int) $metric;
        return $score >= 2 || ($metric && mb_strlen(trim($message)) >= 20);
    }
}
