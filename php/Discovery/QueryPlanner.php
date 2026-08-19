<?php

declare(strict_types=1);

namespace App\Discovery;

/**
 * Cross-domain query planner: search tables → join path → safety → persist.
 */
final class QueryPlanner
{
    private TableProfiler $profiler;
    private EntityResolver $entities;
    private JoinGraphBuilder $graph;
    private JoinSafetyGuard $guard;
    private PiiGuard $pii;

    public function __construct(
        ?TableProfiler $profiler = null,
        ?EntityResolver $entities = null,
        ?JoinGraphBuilder $graph = null,
        ?JoinSafetyGuard $guard = null,
        ?PiiGuard $pii = null,
    ) {
        $this->profiler = $profiler ?? new TableProfiler();
        $this->entities = $entities ?? new EntityResolver();
        $this->graph = $graph ?? new JoinGraphBuilder($this->profiler, $this->entities);
        $this->guard = $guard ?? new JoinSafetyGuard();
        $this->pii = $pii ?? new PiiGuard();
    }

    public function bootstrap(): void
    {
        $this->profiler->ensureProfiled();
        $this->entities->ensureSeeded();
        $this->graph->ensureBuilt();
    }

    /**
     * Semantic-ish search over table cards (domain / entity / column / description).
     *
     * @return array{ok:bool,dataset_id:string,tables:list<array>}
     */
    public function searchTables(string $query, int $limit = 12): array
    {
        $this->bootstrap();
        $q = mb_strtolower(trim($query));
        $scored = [];
        foreach ($this->profiler->latestCards() as $card) {
            if (!$this->pii->domainAllowed($card['domain'] ?? null)) {
                continue;
            }
            $hay = mb_strtolower(implode(' ', [
                $card['table_id'] ?? '',
                $card['domain'] ?? '',
                $card['business_entity'] ?? '',
                $card['description'] ?? '',
                implode(' ', array_column($card['columns'] ?? [], 'name')),
            ]));
            $score = 0;
            if ($q === '') {
                $score = 1;
            } elseif (str_contains($hay, $q)) {
                $score = 10;
            } else {
                foreach (preg_split('/\s+/', $q) ?: [] as $tok) {
                    if ($tok !== '' && str_contains($hay, $tok)) {
                        $score += 3;
                    }
                }
            }
            if ($score > 0) {
                $scored[] = ['score' => $score, 'card' => $this->profiler->publicCard($card)];
            }
        }
        usort($scored, static fn ($a, $b) => $b['score'] <=> $a['score']);
        $limit = max(1, min(30, $limit));
        return [
            'ok' => true,
            'dataset_id' => DiscoveryStore::datasetId(),
            'query' => $query,
            'tables' => array_map(static fn ($x) => $x['card'], array_slice($scored, 0, $limit)),
        ];
    }

    /** @return array<string,mixed> */
    public function describeTable(string $tableId): array
    {
        $this->bootstrap();
        $card = $this->profiler->getCard($tableId);
        if ($card === null) {
            $this->profiler->profileAll([$tableId]);
            $card = $this->profiler->getCard($tableId);
        }
        if ($card === null) {
            return ['ok' => false, 'errors' => ["Unknown table {$tableId}"]];
        }
        if (!$this->pii->domainAllowed($card['domain'] ?? null)) {
            return ['ok' => false, 'errors' => ['Domain not in session scope']];
        }
        // Mask sample rows again defensively
        $pub = $this->profiler->publicCard($card);
        $pub['ok'] = true;
        $pub['dataset_id'] = DiscoveryStore::datasetId();
        $pub['note'] = 'Samples are PII-masked. Use register_table_semantics to persist refinements.';
        return $pub;
    }

    /**
     * Find best join path connecting the given tables (confidence-weighted shortest path).
     *
     * @param list<string> $tableIds
     * @return array<string,mixed>
     */
    public function findJoinPath(array $tableIds, bool $probeIfWeak = true): array
    {
        $this->bootstrap();
        $tables = array_values(array_unique(array_filter(array_map(
            static fn ($t) => trim((string) $t),
            $tableIds
        ), static fn ($t) => $t !== '')));

        if (count($tables) < 2) {
            return [
                'ok' => true,
                'dataset_id' => DiscoveryStore::datasetId(),
                'tables' => $tables,
                'edges' => [],
                'join_path' => [],
                'sql_joins' => [],
                'confidence' => 1.0,
                'fan_out_risk' => 'low',
                'note' => 'Single table — no join needed',
            ];
        }

        // Cached path?
        $pathId = $this->pathId($tables);
        $cached = $this->loadPath($pathId);
        if ($cached !== null) {
            $safety = $this->guard->evaluate($cached);
            return $cached + ['ok' => $safety['ok'], 'safety' => $safety, 'cached' => true];
        }

        $edges = $this->graph->latestEdges();
        $adj = $this->adjacency($edges);
        $ordered = $this->bestSteinerPath($tables, $adj, $edges);
        if ($ordered === null) {
            return [
                'ok' => false,
                'dataset_id' => DiscoveryStore::datasetId(),
                'tables' => $tables,
                'errors' => ['No join path found in graph — describe_table / register_join may be needed'],
                'retry_hint' => 'search_tables then register_join for missing edges, or narrow tables',
            ];
        }

        $pathEdges = $ordered['edges'];
        $pathTables = $ordered['tables'];
        $conf = $ordered['confidence'];
        $fan = $ordered['fan_out_risk'];

        // Probe weak paths (internal — not agent-visible as a separate tool call)
        $probed = false;
        if ($probeIfWeak && $conf < JoinSafetyGuard::ASK_USER_BELOW && $pathEdges !== []) {
            $weak = $pathEdges[array_key_first($pathEdges)];
            $probe = $this->graph->probeEdge($weak);
            $probed = true;
            if (!($probe['ok'] ?? false)) {
                return [
                    'ok' => false,
                    'dataset_id' => DiscoveryStore::datasetId(),
                    'tables' => $pathTables,
                    'edges' => $this->publicEdges($pathEdges),
                    'errors' => ['Live join probe failed: ' . implode('; ', $probe['errors'] ?? ['0 rows'])],
                    'confidence' => $conf,
                    'probed' => true,
                ];
            }
            // Boost confidence after successful probe
            $conf = max($conf, 0.75);
        }

        $sqlJoins = $this->toSqlJoins($pathTables, $pathEdges);
        $result = [
            'ok' => true,
            'dataset_id' => DiscoveryStore::datasetId(),
            'path_id' => $pathId,
            'tables' => $pathTables,
            'edges' => $this->publicEdges($pathEdges),
            'join_path' => array_map(static fn ($e) => $e['edge_id'] ?? '', $pathEdges),
            'sql_joins' => $sqlJoins,
            'confidence' => round($conf, 3),
            'fan_out_risk' => $fan,
            'probed' => $probed,
            'require_preaggregate' => false,
        ];
        $safety = $this->guard->evaluate($result);
        $result['safety'] = $safety;
        $result['require_preaggregate'] = $safety['require_preaggregate'];
        $result['needs_confirmation'] = $safety['needs_confirmation'];
        $result['ok'] = $safety['ok'] || $safety['needs_confirmation'];
        // Persist successful paths
        if ($safety['ok'] || $safety['needs_confirmation']) {
            $this->persistPath($pathId, $result);
        }
        if ($safety['needs_confirmation']) {
            $result['ask_user_hint'] = sprintf(
                'Bu ilişkiden emin değilim (confidence=%.2f). %s tablolarını %s üzerinden bağlıyorum — doğru mu?',
                $conf,
                implode(', ', $pathTables),
                implode(' → ', array_map(
                    static fn ($e) => ($e['table_a'] ?? '') . '.' . ($e['column_a'] ?? ''),
                    $pathEdges
                ))
            );
        }
        return $result;
    }

    /**
     * Build FROM clause fragment from a planner path (used by AnalyticsTool).
     *
     * @param list<string> $tableIds
     * @return array{ok:bool,sql?:string,path?:array,errors?:list<string>,warnings?:list<string>}
     */
    public function buildFromForTables(array $tableIds): array
    {
        $path = $this->findJoinPath($tableIds, true);
        if (!($path['ok'] ?? false) && empty($path['needs_confirmation'])) {
            return [
                'ok' => false,
                'errors' => $path['errors'] ?? ['join path failed'],
                'path' => $path,
            ];
        }
        if (!empty($path['needs_confirmation']) && !($path['safety']['ok'] ?? false)
            && ($path['confidence'] ?? 0) < JoinSafetyGuard::MIN_AUTO_CONFIDENCE
        ) {
            return [
                'ok' => false,
                'errors' => $path['errors'] ?? ['confidence too low'],
                'path' => $path,
                'ask_user_hint' => $path['ask_user_hint'] ?? null,
            ];
        }

        $tables = $path['tables'] ?? $tableIds;
        $edges = [];
        foreach ($path['edges'] ?? [] as $e) {
            $edges[] = $e;
        }
        if ($tables === []) {
            return ['ok' => false, 'errors' => ['no tables']];
        }
        $root = $tables[0];
        $sql = $root;
        $included = [$root => true];
        // Attach edges greedily
        for ($pass = 0; $pass < 12; $pass++) {
            $progress = false;
            foreach ($edges as $e) {
                $a = (string) ($e['table_a'] ?? '');
                $b = (string) ($e['table_b'] ?? '');
                $ca = (string) ($e['column_a'] ?? '');
                $cb = (string) ($e['column_b'] ?? '');
                if (isset($included[$a]) && !isset($included[$b])) {
                    $sql .= "\nJOIN {$b} ON {$b}.{$cb} = {$a}.{$ca}";
                    $included[$b] = true;
                    $progress = true;
                } elseif (isset($included[$b]) && !isset($included[$a])) {
                    $sql .= "\nJOIN {$a} ON {$a}.{$ca} = {$b}.{$cb}";
                    $included[$a] = true;
                    $progress = true;
                }
            }
            $all = true;
            foreach ($tables as $t) {
                if (!isset($included[$t])) {
                    $all = false;
                    break;
                }
            }
            if ($all) {
                return [
                    'ok' => true,
                    'sql' => $sql,
                    'path' => $path,
                    'warnings' => $path['safety']['warnings'] ?? [],
                ];
            }
            if (!$progress) {
                break;
            }
        }
        return ['ok' => false, 'errors' => ['Could not assemble SQL from join path'], 'path' => $path];
    }

    /**
     * @param list<array<string,mixed>> $edges
     * @return array<string,list<array{to:string,edge:array}>>
     */
    private function adjacency(array $edges): array
    {
        $adj = [];
        foreach ($edges as $e) {
            $a = (string) $e['table_a'];
            $b = (string) $e['table_b'];
            $adj[$a][] = ['to' => $b, 'edge' => $e];
            $adj[$b][] = ['to' => $a, 'edge' => $e];
        }
        return $adj;
    }

    /**
     * Greedy: order targets, chain shortest confidence-weighted paths.
     *
     * @param list<string> $targets
     * @param array<string,list<array{to:string,edge:array}>> $adj
     * @param list<array<string,mixed>> $allEdges
     * @return array{tables:list<string>,edges:list<array>,confidence:float,fan_out_risk:string}|null
     */
    private function bestSteinerPath(array $targets, array $adj, array $allEdges): ?array
    {
        // Pick root = first target that exists in graph, else first
        $root = $targets[0];
        foreach ($targets as $t) {
            if (isset($adj[$t])) {
                $root = $t;
                break;
            }
        }

        $orderedTables = [$root];
        $usedEdges = [];
        $included = [$root => true];
        $minConf = 1.0;
        $worstFan = 'low';

        $remaining = array_values(array_filter($targets, static fn ($t) => $t !== $root));
        while ($remaining !== []) {
            $best = null;
            $bestCost = PHP_FLOAT_MAX;
            $bestTarget = null;
            foreach ($remaining as $t) {
                $sp = $this->shortestPath($rootFrom = array_key_last($included) !== null
                    ? $this->nearestIncluded($t, $included, $adj)
                    : $root, $t, $adj);
                // Try from any included node
                foreach (array_keys($included) as $from) {
                    $cand = $this->shortestPath($from, $t, $adj);
                    if ($cand === null) {
                        continue;
                    }
                    $cost = $cand['cost'];
                    if ($cost < $bestCost) {
                        $bestCost = $cost;
                        $best = $cand;
                        $bestTarget = $t;
                    }
                }
            }
            if ($best === null || $bestTarget === null) {
                return null;
            }
            foreach ($best['edges'] as $e) {
                $usedEdges[$e['edge_id']] = $e;
                $minConf = min($minConf, (float) ($e['confidence_score'] ?? 0.5));
                $fan = strtolower((string) ($e['fan_out_risk'] ?? 'medium'));
                $worstFan = $this->worseFan($worstFan, $fan);
            }
            foreach ($best['nodes'] as $n) {
                if (!isset($included[$n])) {
                    $orderedTables[] = $n;
                    $included[$n] = true;
                }
            }
            $remaining = array_values(array_filter($remaining, static fn ($t) => $t !== $bestTarget));
        }

        // Ensure all targets present
        foreach ($targets as $t) {
            if (!isset($included[$t])) {
                return null;
            }
        }

        return [
            'tables' => array_values(array_unique($orderedTables)),
            'edges' => array_values($usedEdges),
            'confidence' => $minConf,
            'fan_out_risk' => $worstFan,
        ];
    }

    /**
     * @param array<string,bool> $included
     * @param array<string,list<array{to:string,edge:array}>> $adj
     */
    private function nearestIncluded(string $target, array $included, array $adj): string
    {
        foreach (array_keys($included) as $from) {
            return $from;
        }
        return $target;
    }

    /**
     * Dijkstra with cost = 1 / max(confidence, 0.05).
     *
     * @param array<string,list<array{to:string,edge:array}>> $adj
     * @return array{nodes:list<string>,edges:list<array>,cost:float}|null
     */
    private function shortestPath(string $from, string $to, array $adj): ?array
    {
        if ($from === $to) {
            return ['nodes' => [$from], 'edges' => [], 'cost' => 0.0];
        }
        $dist = [$from => 0.0];
        $prev = [];
        $prevEdge = [];
        $queue = [$from];
        while ($queue !== []) {
            $u = array_shift($queue);
            if ($u === $to) {
                break;
            }
            foreach ($adj[$u] ?? [] as $link) {
                $v = $link['to'];
                $e = $link['edge'];
                $conf = max(0.05, (float) ($e['confidence_score'] ?? 0.5));
                $w = 1.0 / $conf;
                $alt = ($dist[$u] ?? PHP_FLOAT_MAX) + $w;
                if ($alt < ($dist[$v] ?? PHP_FLOAT_MAX)) {
                    $dist[$v] = $alt;
                    $prev[$v] = $u;
                    $prevEdge[$v] = $e;
                    $queue[] = $v;
                }
            }
        }
        if (!isset($dist[$to])) {
            return null;
        }
        $nodes = [$to];
        $edges = [];
        for ($cur = $to; $cur !== $from; $cur = $prev[$cur]) {
            if (!isset($prev[$cur])) {
                return null;
            }
            $edges[] = $prevEdge[$cur];
            $nodes[] = $prev[$cur];
        }
        return [
            'nodes' => array_reverse($nodes),
            'edges' => array_reverse($edges),
            'cost' => $dist[$to],
        ];
    }

    private function worseFan(string $a, string $b): string
    {
        $rank = ['low' => 1, 'medium' => 2, 'high' => 3];
        return ($rank[$b] ?? 2) > ($rank[$a] ?? 2) ? $b : $a;
    }

    /** @param list<array<string,mixed>> $edges */
    private function publicEdges(array $edges): array
    {
        $out = [];
        foreach ($edges as $e) {
            $out[] = [
                'edge_id' => $e['edge_id'] ?? null,
                'table_a' => $e['table_a'] ?? null,
                'column_a' => $e['column_a'] ?? null,
                'table_b' => $e['table_b'] ?? null,
                'column_b' => $e['column_b'] ?? null,
                'confidence_score' => isset($e['confidence_score']) ? (float) $e['confidence_score'] : null,
                'source' => $e['source'] ?? null,
                'fan_out_risk' => $e['fan_out_risk'] ?? null,
                'cardinality' => $e['cardinality'] ?? null,
            ];
        }
        return $out;
    }

    /**
     * @param list<string> $tables
     * @param list<array<string,mixed>> $edges
     * @return list<string>
     */
    private function toSqlJoins(array $tables, array $edges): array
    {
        $out = [];
        foreach ($edges as $e) {
            $out[] = sprintf(
                '%s.%s = %s.%s',
                $e['table_a'] ?? '',
                $e['column_a'] ?? '',
                $e['table_b'] ?? '',
                $e['column_b'] ?? ''
            );
        }
        return $out;
    }

    /** @param list<string> $tables */
    private function pathId(array $tables): string
    {
        $t = array_map('strtolower', $tables);
        sort($t);
        return substr(md5(implode('|', $t)), 0, 16);
    }

    private function loadPath(string $pathId): ?array
    {
        $ds = DiscoveryStore::datasetId();
        $st = DiscoveryStore::pdo()->prepare(
            'SELECT * FROM join_paths WHERE dataset_id = ? AND path_id = ?'
        );
        $st->execute([$ds, $pathId]);
        $row = $st->fetch();
        if (!$row) {
            return null;
        }
        // bump usage
        DiscoveryStore::pdo()->prepare(
            'UPDATE join_paths SET usage_count = usage_count + 1, updated_at = ? WHERE dataset_id = ? AND path_id = ?'
        )->execute([gmdate('c'), $ds, $pathId]);

        return [
            'path_id' => $pathId,
            'tables' => json_decode((string) $row['tables_json'], true) ?: [],
            'edges' => json_decode((string) $row['edges_json'], true) ?: [],
            'join_path' => array_column(json_decode((string) $row['edges_json'], true) ?: [], 'edge_id'),
            'sql_joins' => array_filter(explode("\n", (string) ($row['sql_fragment'] ?? ''))),
            'confidence' => (float) ($row['confidence_score'] ?? 0),
            'fan_out_risk' => $row['fan_out_risk'] ?? 'medium',
            'dataset_id' => $ds,
        ];
    }

    /** @param array<string,mixed> $result */
    private function persistPath(string $pathId, array $result): void
    {
        $ds = DiscoveryStore::datasetId();
        $now = gmdate('c');
        DiscoveryStore::pdo()->prepare(
            'INSERT INTO join_paths (
                path_id, dataset_id, tables_json, edges_json, confidence_score,
                fan_out_risk, sql_fragment, usage_count, created_at, updated_at
             ) VALUES (?,?,?,?,?,?,?,1,?,?)
             ON CONFLICT(dataset_id, path_id) DO UPDATE SET
               usage_count = join_paths.usage_count + 1,
               confidence_score = excluded.confidence_score,
               updated_at = excluded.updated_at'
        )->execute([
            $pathId,
            $ds,
            json_encode($result['tables'] ?? [], JSON_UNESCAPED_UNICODE),
            json_encode($result['edges'] ?? [], JSON_UNESCAPED_UNICODE),
            $result['confidence'] ?? null,
            $result['fan_out_risk'] ?? null,
            implode("\n", $result['sql_joins'] ?? []),
            $now,
            $now,
        ]);
    }
}
