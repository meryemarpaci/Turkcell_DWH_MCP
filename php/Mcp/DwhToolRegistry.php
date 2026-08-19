<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Discovery\EntityResolver;
use App\Discovery\JoinGraphBuilder;
use App\Discovery\QueryPlanner;
use App\Discovery\TableProfiler;
use App\Semantic\RegistryService;
use App\Semantic\RegistryStore;
use App\SemanticConfig;
use App\Tools\AnalyticsTool;
use App\Tools\ProbeTool;
use App\Tools\ReportTool;
use App\Tools\SchemaTool;
use App\Tools\SqlGuard;

/**
 * Registers all DWH analyst tools on the given McpServer.
 * Mirrors the tool surface from AgentOrchestrator::toolDeclarations()
 * so LLM function-calling schema and MCP schema stay identical.
 */
final class DwhToolRegistry
{
    /** @var array<string,callable>|null */
    private static ?array $handlers = null;

    /**
     * @param  array<string,mixed> $args
     * @return array<string,mixed>
     */
    public static function dispatch(string $name, array $args): array
    {
        $ds = trim((string) ($args['dataset_id'] ?? ''));
        if ($ds !== '') {
            RegistryStore::setActiveDataset($ds);
        }

        if (self::$handlers === null) {
            self::$handlers = self::buildHandlers();
        }
        if (!isset(self::$handlers[$name])) {
            return ['ok' => false, 'errors' => ["Unknown tool: {$name}"]];
        }
        try {
            return (self::$handlers[$name])($args);
        } catch (\Throwable $e) {
            return ['ok' => false, 'errors' => [$e->getMessage()]];
        }
    }

    /** Bind session active dataset (orchestrator). */
    public static function setActiveDataset(?string $datasetId): void
    {
        RegistryStore::setActiveDataset($datasetId);
        self::$handlers = null;
    }

    /** @return array<string,callable> */
    private static function buildHandlers(): array
    {
        $guard = new SqlGuard(SemanticConfig::allowedTables());
        $schema = new SchemaTool();
        $probe = new ProbeTool($guard);
        $report = new ReportTool($guard);
        $analytics = new AnalyticsTool($report);
        $registry = static fn (): RegistryService => $analytics->registry();
        $planner = new QueryPlanner();
        $profiler = new TableProfiler();
        $graph = new JoinGraphBuilder($profiler, new EntityResolver());

        $datasetPropNote = static function (array $result): array {
            if (!isset($result['dataset_id'])) {
                $result['dataset_id'] = RegistryStore::datasetId();
            }
            return $result;
        };

        return [
            'list_schema' => static fn(array $a) => $datasetPropNote($schema->listSchema(
                isset($a['tables']) && is_array($a['tables']) ? $a['tables'] : null
            )),
            'search_tables' => static fn(array $a) => $datasetPropNote(
                $planner->searchTables(
                    (string) ($a['query'] ?? ''),
                    isset($a['limit']) ? (int) $a['limit'] : 12
                )
            ),
            'describe_table' => static fn(array $a) => $datasetPropNote(
                $planner->describeTable((string) ($a['table_id'] ?? $a['table'] ?? ''))
            ),
            'find_join_path' => static fn(array $a) => $datasetPropNote(
                $planner->findJoinPath(
                    self::stringList($a['table_ids'] ?? $a['tables'] ?? []),
                    !array_key_exists('probe_if_weak', $a) || (bool) $a['probe_if_weak']
                )
            ),
            'register_table_semantics' => static fn(array $a) => $datasetPropNote(
                $profiler->registerSemantics($a)
            ),
            'register_join' => static fn(array $a) => $datasetPropNote(
                $graph->registerJoin($a)
            ),
            'register_canonical_entity' => static fn(array $a) => $datasetPropNote(
                (new EntityResolver())->register(
                    (string) ($a['entity_type'] ?? ''),
                    self::stringList($a['aliases'] ?? []),
                    self::nullString($a['value_pattern'] ?? null),
                    'agent',
                    isset($a['confidence']) ? (float) $a['confidence'] : 0.8,
                    !empty($a['verified']),
                    self::nullString($a['description'] ?? null)
                )
            ),
            'search_metrics' => static fn(array $a) => $datasetPropNote(
                $registry()->search(
                    (string) ($a['query'] ?? ''),
                    isset($a['limit']) ? (int) $a['limit'] : 15
                )
            ),
            'list_metrics' => static fn(array $a) => $datasetPropNote([
                'ok' => true,
                'metrics' => $analytics->listMetrics(),
                'dimensions' => $analytics->listDimensions(),
                'entities' => $analytics->listEntities(),
                'note' => 'Prefer search_metrics(query) to avoid context bloat. '
                    . 'For "each X top Y" use analyze_top_per_group.',
            ]),
            'describe_column' => static fn(array $a) => $datasetPropNote(
                $registry()->describeColumn(
                    (string) ($a['table'] ?? ''),
                    (string) ($a['column'] ?? ''),
                    isset($a['limit']) ? (int) $a['limit'] : 8
                )
            ),
            'register_metric' => static fn(array $a) => $datasetPropNote(
                $registry()->registerMetric([
                    'metric_id' => (string) ($a['metric_id'] ?? ''),
                    'expression' => (string) ($a['expression'] ?? ''),
                    'source_column' => $a['source_column'] ?? null,
                    'aggregation' => $a['aggregation'] ?? null,
                    'grain' => $a['grain'] ?? null,
                    'description' => $a['description'] ?? null,
                    'created_by' => 'agent',
                    'verified' => !empty($a['verified']),
                ])
            ),
            'register_dimension' => static fn(array $a) => $datasetPropNote(
                $registry()->registerDimension([
                    'dimension_id' => (string) ($a['dimension_id'] ?? ''),
                    'expr' => (string) ($a['expr'] ?? $a['expression'] ?? ''),
                    'source_column' => $a['source_column'] ?? null,
                    'tables' => $a['tables'] ?? [],
                    'joins' => $a['joins'] ?? [],
                    'join_path' => $a['join_path'] ?? null,
                    'cardinality' => $a['cardinality'] ?? null,
                    'type' => $a['type'] ?? null,
                    'description' => $a['description'] ?? null,
                    'entity' => !empty($a['entity']),
                    'created_by' => 'agent',
                    'verified' => !empty($a['verified']),
                ])
            ),
            'propose_tables' => static fn(array $a) => $schema->proposeTables((string) ($a['intent_hint'] ?? '')),
            'probe_join' => static fn(array $a) => $probe->probeJoin(
                isset($a['join_ids']) && is_array($a['join_ids']) ? $a['join_ids'] : [],
                isset($a['sql']) ? (string) $a['sql'] : null
            ),
            'probe_filter' => static fn(array $a) => $probe->probeFilter((string) ($a['sql'] ?? '')),
            'analyze_kpi' => static fn(array $a) => $datasetPropNote($analytics->analyzeKpi(
                self::stringList($a['metrics'] ?? []),
                self::nullString($a['date_from'] ?? null),
                self::nullString($a['date_to'] ?? null),
                self::parseFilters($a['filters'] ?? []),
                !array_key_exists('apply_default_status', $a) || (bool) $a['apply_default_status'],
                (string) ($a['title'] ?? 'KPI')
            )),
            'analyze_breakdown' => static fn(array $a) => $datasetPropNote($analytics->analyzeBreakdown(
                self::stringList($a['metrics'] ?? []),
                $a['dimensions'] ?? [],
                self::nullString($a['date_from'] ?? null),
                self::nullString($a['date_to'] ?? null),
                self::parseFilters($a['filters'] ?? []),
                !array_key_exists('apply_default_status', $a) || (bool) $a['apply_default_status'],
                isset($a['top_n']) ? (int) $a['top_n'] : null,
                (string) ($a['title'] ?? 'Breakdown')
            )),
            'analyze_top_per_group' => static fn(array $a) => $datasetPropNote($analytics->analyzeTopPerGroup(
                (string) ($a['partition_by'] ?? ''),
                (string) ($a['rank_dimension'] ?? ''),
                self::stringList($a['metrics'] ?? []),
                self::stringList($a['extra_dimensions'] ?? []),
                isset($a['top_n_per_group']) ? (int) $a['top_n_per_group'] : 1,
                self::nullString($a['date_from'] ?? null),
                self::nullString($a['date_to'] ?? null),
                self::parseFilters($a['filters'] ?? []),
                !array_key_exists('apply_default_status', $a) || (bool) $a['apply_default_status'],
                (string) ($a['title'] ?? 'Top per group')
            )),
            'analyze_trend' => static fn(array $a) => $datasetPropNote($analytics->analyzeTrend(
                self::stringList($a['metrics'] ?? []),
                (string) ($a['grain'] ?? 'month'),
                self::nullString($a['date_from'] ?? null),
                self::nullString($a['date_to'] ?? null),
                self::parseFilters($a['filters'] ?? []),
                !array_key_exists('apply_default_status', $a) || (bool) $a['apply_default_status'],
                (string) ($a['title'] ?? 'Trend')
            )),
            'run_report' => static function (array $a) use ($report, $datasetPropNote): array {
                return $datasetPropNote($report->runReport(
                    (string) ($a['sql'] ?? ''),
                    strtolower((string) ($a['report_type'] ?? 'table')),
                    (string) ($a['report_id'] ?? 'report'),
                    (string) ($a['title'] ?? 'Report'),
                    null
                ));
            },
            'execute_query' => static fn(array $a) => $report->executeQuery(
                (string) ($a['sql'] ?? ''),
                10,
                'peek'
            ),
            'ask_user' => static fn(array $a): array => [
                'ok' => true,
                'dataset_id' => RegistryStore::datasetId(),
                'message' => (string) ($a['message'] ?? ''),
                'questions' => $a['questions'] ?? [],
                'filter_suggestions' => $a['filter_suggestions'] ?? SemanticConfig::all()['filter_hints'] ?? [],
            ],
        ];
    }

    public static function register(McpServer $server): void
    {
        foreach (self::allToolSchemas() as $spec) {
            $name = $spec['name'];
            $server->register(
                $name,
                $spec['description'],
                $spec['parameters'],
                static function (array $args) use ($name): array {
                    return self::dispatch($name, $args);
                }
            );
        }
    }

    /**
     * Shared OpenAI/MCP tool schemas (single source of truth).
     *
     * @return list<array{name:string,description:string,parameters:array<string,mixed>}>
     */
    public static function toolSchemas(): array
    {
        return array_values(array_filter(
            self::allToolSchemas(),
            static fn (array $t) => in_array($t['name'], self::agentToolNames(), true)
        ));
    }

    /** Tools exposed to the LLM agent (slim, registry-backed analyze surface). */
    public static function agentToolNames(): array
    {
        return [
            'search_tables',
            'describe_table',
            'find_join_path',
            'register_table_semantics',
            'register_join',
            'register_canonical_entity',
            'search_metrics',
            'describe_column',
            'register_metric',
            'register_dimension',
            'analyze_kpi',
            'analyze_breakdown',
            'analyze_top_per_group',
            'analyze_trend',
            'list_schema',
            'ask_user',
        ];
    }

    /**
     * Full MCP catalog including peeks / advanced SQL (for external MCP clients).
     *
     * @return list<array{name:string,description:string,parameters:array<string,mixed>}>
     */
    public static function allToolSchemas(): array
    {
        $datasetId = [
            'type' => 'string',
            'description' => 'Optional dataset_id; defaults to session active dataset',
        ];
        $filterProp = [
            'type' => 'array',
            'description' => 'Filters as [{field,value}] using registry dimension_id, e.g. [{"field":"customer_state","value":"SP"}]',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'field' => [
                        'type' => 'string',
                        'description' => 'Registry dimension_id',
                    ],
                    'value' => [
                        'type' => 'string',
                        'description' => 'Filter value',
                    ],
                ],
                'required' => ['field', 'value'],
            ],
        ];
        $metricsProp = [
            'type' => 'array',
            'items' => ['type' => 'string'],
            'description' => 'Registry metric_id list (from search_metrics)',
        ];
        $dateFrom = ['type' => 'string', 'description' => 'Inclusive start YYYY-MM-DD (from DATA CALENDAR)'];
        $dateTo = ['type' => 'string', 'description' => 'Inclusive end YYYY-MM-DD'];

        return [
            [
                'name' => 'search_tables',
                'description' => 'Cross-domain catalog search: find relevant tables/domains by free text (CRM, billing, network, …).',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string'],
                        'limit' => ['type' => 'integer'],
                        'dataset_id' => $datasetId,
                    ],
                ],
            ],
            [
                'name' => 'describe_table',
                'description' => 'Table identity card: columns, masked samples, domain/entity guesses. Then register_table_semantics if refining.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'table_id' => ['type' => 'string'],
                        'dataset_id' => $datasetId,
                    ],
                    'required' => ['table_id'],
                ],
            ],
            [
                'name' => 'find_join_path',
                'description' => 'Resolve safest join path across tables via join graph (confidence + fan-out risk). Persist for reuse.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'table_ids' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Tables to connect, e.g. ["fact_orders","dim_customer","fact_order_items"]',
                        ],
                        'probe_if_weak' => [
                            'type' => 'boolean',
                            'description' => 'Default true: planner may live-probe weak edges internally',
                        ],
                        'dataset_id' => $datasetId,
                    ],
                    'required' => ['table_ids'],
                ],
            ],
            [
                'name' => 'register_table_semantics',
                'description' => 'Persist refined table domain/entity/description into the discovery catalog (once).',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'table_id' => ['type' => 'string'],
                        'domain' => ['type' => 'string'],
                        'business_entity' => ['type' => 'string'],
                        'description' => ['type' => 'string'],
                        'candidate_pk' => ['type' => 'string'],
                        'verified' => ['type' => 'boolean'],
                        'dataset_id' => $datasetId,
                    ],
                    'required' => ['table_id'],
                ],
            ],
            [
                'name' => 'register_join',
                'description' => 'Persist a discovered/validated join edge into the join graph (once).',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'table_a' => ['type' => 'string'],
                        'column_a' => ['type' => 'string'],
                        'table_b' => ['type' => 'string'],
                        'column_b' => ['type' => 'string'],
                        'confidence_score' => ['type' => 'number'],
                        'fan_out_risk' => ['type' => 'string', 'description' => 'low|medium|high'],
                        'cardinality' => ['type' => 'string'],
                        'verified' => ['type' => 'boolean'],
                        'dataset_id' => $datasetId,
                    ],
                    'required' => ['table_a', 'column_a', 'table_b', 'column_b'],
                ],
            ],
            [
                'name' => 'register_canonical_entity',
                'description' => 'Map alias column names to one business entity (e.g. musteri ← msisdn, customer_no, sub_id).',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'entity_type' => ['type' => 'string'],
                        'aliases' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'value_pattern' => ['type' => 'string'],
                        'description' => ['type' => 'string'],
                        'verified' => ['type' => 'boolean'],
                        'dataset_id' => $datasetId,
                    ],
                    'required' => ['entity_type', 'aliases'],
                ],
            ],
            [
                'name' => 'search_metrics',
                'description' => 'Search semantic registry for metric_id / dimension_id. Returns top matches only (not full dump).',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Free text, e.g. seller, gmv, category'],
                        'limit' => ['type' => 'integer', 'description' => 'Max results per kind (default 15)'],
                        'dataset_id' => $datasetId,
                    ],
                ],
            ],
            [
                'name' => 'list_metrics',
                'description' => 'Legacy full-ish list. Prefer search_metrics.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => ['dataset_id' => $datasetId],
                ],
            ],
            [
                'name' => 'describe_column',
                'description' => 'Discovery: sample values/type/cardinality for a physical column. Then register_* once.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'table' => ['type' => 'string'],
                        'column' => ['type' => 'string'],
                        'limit' => ['type' => 'integer'],
                        'dataset_id' => $datasetId,
                    ],
                    'required' => ['table', 'column'],
                ],
            ],
            [
                'name' => 'register_metric',
                'description' => 'Persist a discovered measure into the semantic registry (once). Then analyze_kpi with metric_id.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'metric_id' => ['type' => 'string'],
                        'expression' => [
                            'type' => 'string',
                            'description' => 'SQL aggregate expression, e.g. SUM(oi.price)',
                        ],
                        'source_column' => ['type' => 'string'],
                        'aggregation' => ['type' => 'string', 'description' => 'SUM|AVG|COUNT|…'],
                        'grain' => ['type' => 'string'],
                        'description' => ['type' => 'string'],
                        'verified' => ['type' => 'boolean'],
                        'dataset_id' => $datasetId,
                    ],
                    'required' => ['metric_id', 'expression'],
                ],
            ],
            [
                'name' => 'register_dimension',
                'description' => 'Persist a discovered dimension into the semantic registry (once). Then analyze_* with dimension_id.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'dimension_id' => ['type' => 'string'],
                        'expr' => ['type' => 'string', 'description' => 'SQL column expr, e.g. s.seller_id'],
                        'source_column' => ['type' => 'string'],
                        'tables' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'joins' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'description' => ['type' => 'string'],
                        'entity' => ['type' => 'boolean', 'description' => 'True for high-cardinality entity keys'],
                        'verified' => ['type' => 'boolean'],
                        'dataset_id' => $datasetId,
                    ],
                    'required' => ['dimension_id', 'expr'],
                ],
            ],
            [
                'name' => 'analyze_kpi',
                'description' => 'FULL-DATA KPI. Resolves metric_id from registry, computes over entire filtered warehouse. Joins via join graph when multi-table.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'metrics' => $metricsProp,
                        'date_from' => $dateFrom,
                        'date_to' => $dateTo,
                        'filters' => $filterProp,
                        'apply_default_status' => [
                            'type' => 'boolean',
                            'description' => 'Default true (delivered). Set false to include all statuses.',
                        ],
                        'title' => ['type' => 'string'],
                        'dataset_id' => $datasetId,
                    ],
                ],
            ],
            [
                'name' => 'analyze_breakdown',
                'description' => 'FULL-DATA breakdown by registry dimension_id(s). For top stores use dimensions=["seller_id"] + top_n. '
                    . 'Do NOT use for "each X top Y" — use analyze_top_per_group.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'metrics' => $metricsProp,
                        'dimensions' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Registry dimension_id list',
                        ],
                        'date_from' => $dateFrom,
                        'date_to' => $dateTo,
                        'filters' => $filterProp,
                        'top_n' => [
                            'type' => 'integer',
                            'description' => 'For top entities (e.g. top 10 sellers). Omit for low-cardinality dims.',
                        ],
                        'apply_default_status' => ['type' => 'boolean'],
                        'title' => ['type' => 'string'],
                        'dataset_id' => $datasetId,
                    ],
                    'required' => ['dimensions'],
                ],
            ],
            [
                'name' => 'analyze_top_per_group',
                'description' => 'FULL-DATA ranking inside each entity. partition_by / rank_dimension are registry dimension_ids. '
                    . 'Never set rank_dimension to a metric.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'partition_by' => [
                            'type' => 'string',
                            'description' => 'Entity dimension_id, e.g. seller_id',
                        ],
                        'rank_dimension' => [
                            'type' => 'string',
                            'description' => 'Attribute dimension_id to rank, e.g. category',
                        ],
                        'metrics' => $metricsProp,
                        'extra_dimensions' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Extra dimension_ids to keep, e.g. seller_state',
                        ],
                        'top_n_per_group' => [
                            'type' => 'integer',
                            'description' => 'Top N inside each partition (default 1)',
                        ],
                        'date_from' => $dateFrom,
                        'date_to' => $dateTo,
                        'filters' => $filterProp,
                        'apply_default_status' => ['type' => 'boolean'],
                        'title' => ['type' => 'string'],
                        'dataset_id' => $datasetId,
                    ],
                    'required' => ['partition_by', 'rank_dimension'],
                ],
            ],
            [
                'name' => 'analyze_trend',
                'description' => 'FULL-DATA trend. Time series of registry metrics (day|month|year).',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'metrics' => $metricsProp,
                        'grain' => ['type' => 'string', 'description' => 'day|month|year'],
                        'date_from' => $dateFrom,
                        'date_to' => $dateTo,
                        'filters' => $filterProp,
                        'apply_default_status' => ['type' => 'boolean'],
                        'title' => ['type' => 'string'],
                        'dataset_id' => $datasetId,
                    ],
                ],
            ],
            [
                'name' => 'list_schema',
                'description' => 'List physical DWH tables/columns for discovery. Prefer search_tables / describe_table.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'tables' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                        ],
                        'dataset_id' => $datasetId,
                    ],
                ],
            ],
            [
                'name' => 'propose_tables',
                'description' => 'Heuristic table suggestions from intent text',
                'parameters' => [
                    'type' => 'object',
                    'properties' => ['intent_hint' => ['type' => 'string']],
                    'required' => ['intent_hint'],
                ],
            ],
            [
                'name' => 'probe_join',
                'description' => 'Optional join peek (COUNT + tiny sample). Prefer analyze_* for analysis.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'join_ids' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'sql' => ['type' => 'string'],
                    ],
                ],
            ],
            [
                'name' => 'probe_filter',
                'description' => 'Optional filter peek. Prefer analyze_* for analysis.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => ['sql' => ['type' => 'string']],
                    'required' => ['sql'],
                ],
            ],
            [
                'name' => 'run_report',
                'description' => 'Advanced escape hatch only. Prefer analyze_*. Do not use LIMIT for analysis.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'sql' => ['type' => 'string'],
                        'report_type' => ['type' => 'string'],
                        'report_id' => ['type' => 'string'],
                        'title' => ['type' => 'string'],
                    ],
                    'required' => ['sql'],
                ],
            ],
            [
                'name' => 'execute_query',
                'description' => 'Tiny control peek (≤10 rows). Not for analysis.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => ['sql' => ['type' => 'string']],
                    'required' => ['sql'],
                ],
            ],
            [
                'name' => 'ask_user',
                'description' => 'Clarify when a join/metric/dimension role is ambiguous before register_*.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'message' => ['type' => 'string'],
                        'questions' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'filter_suggestions' => ['type' => 'array', 'items' => ['type' => 'object']],
                        'dataset_id' => $datasetId,
                    ],
                    'required' => ['message'],
                ],
            ],
        ];
    }

    /** @param mixed $v @return list<string> */
    private static function stringList(mixed $v): array
    {
        if (!is_array($v)) {
            return $v !== null && $v !== '' ? [(string) $v] : [];
        }
        $out = [];
        foreach ($v as $item) {
            $s = trim((string) $item);
            if ($s !== '') {
                $out[] = $s;
            }
        }
        return $out;
    }

    private static function nullString(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }

    /** @param mixed $filters @return array<string,scalar|null> */
    private static function parseFilters(mixed $filters): array
    {
        if (!is_array($filters)) {
            return [];
        }
        if ($filters === []) {
            return [];
        }
        if (array_is_list($filters)) {
            $out = [];
            foreach ($filters as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $field = (string) ($row['field'] ?? $row['name'] ?? '');
                if ($field === '') {
                    continue;
                }
                $out[$field] = $row['value'] ?? null;
            }
            return $out;
        }
        /** @var array<string,scalar|null> $filters */
        return $filters;
    }
}
