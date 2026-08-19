<?php

declare(strict_types=1);

namespace App\Discovery;

use App\SemanticConfig;
use App\Tools\Database;
use App\Tools\ProbeTool;
use App\Tools\SqlGuard;

/**
 * Discover & score join edges: FK > name/canonical match > value overlap probe.
 */
final class JoinGraphBuilder
{
    private TableProfiler $profiler;
    private EntityResolver $entities;

    public function __construct(?TableProfiler $profiler = null, ?EntityResolver $entities = null)
    {
        $this->profiler = $profiler ?? new TableProfiler();
        $this->entities = $entities ?? new EntityResolver();
    }

    /**
     * Rebuild / enrich join graph from profile cards + existing SemanticConfig joins + probes.
     *
     * @return array{ok:bool,dataset_id:string,edges:int,new:int}
     */
    public function rebuild(bool $runValueProbes = true): array
    {
        $this->profiler->ensureProfiled();
        $this->entities->ensureSeeded();
        $this->entities->enrichFromCards($this->profiler);

        $ds = DiscoveryStore::datasetId();
        $before = count($this->latestEdges());
        $seen = [];

        // 1) Existing profile / FK joins (highest trust)
        foreach (SemanticConfig::joins() as $j) {
            $a = (string) ($j['left_table'] ?? '');
            $b = (string) ($j['right_table'] ?? '');
            $ca = (string) ($j['left_key'] ?? '');
            $cb = (string) ($j['right_key'] ?? '');
            if ($a === '' || $b === '' || $ca === '' || $cb === '') {
                continue;
            }
            $source = (string) ($j['source'] ?? 'profile');
            if ($source === 'fk') {
                $conf = 0.95;
            } elseif ($source === 'heuristic') {
                $conf = 0.7;
            } else {
                $conf = 0.9;
            }
            $edgeId = $this->edgeId($a, $ca, $b, $cb);
            $this->upsertEdge([
                'edge_id' => $edgeId,
                'table_a' => $a,
                'column_a' => $ca,
                'table_b' => $b,
                'column_b' => $cb,
                'confidence_score' => $conf,
                'source' => $source === 'fk' ? 'fk' : ($source === 'heuristic' ? 'name_match' : 'profile'),
                'fan_out_risk' => $this->fanOutFromCardinality((string) ($j['cardinality'] ?? '')),
                'cardinality' => $j['cardinality'] ?? null,
                'verified_by' => 'auto',
                'verified' => $source === 'fk' || $source === 'profile',
            ]);
            $seen[$edgeId] = true;
        }

        // 2) Canonical / name matches across table cards
        $cards = $this->profiler->latestCards();
        $n = count($cards);
        for ($i = 0; $i < $n; $i++) {
            for ($k = $i + 1; $k < $n; $k++) {
                $candidates = $this->nameMatchCandidates($cards[$i], $cards[$k]);
                foreach ($candidates as $cand) {
                    $edgeId = $this->edgeId($cand['table_a'], $cand['column_a'], $cand['table_b'], $cand['column_b']);
                    if (isset($seen[$edgeId])) {
                        continue;
                    }
                    $conf = $cand['confidence'];
                    if ($runValueProbes && $conf < 0.85) {
                        $overlap = $this->valueOverlap(
                            $cand['table_a'],
                            $cand['column_a'],
                            $cand['table_b'],
                            $cand['column_b']
                        );
                        if ($overlap !== null) {
                            $conf = max($conf, 0.4 + 0.55 * $overlap);
                            $cand['source'] = 'value_overlap';
                        }
                    }
                    if ($conf < 0.45) {
                        continue;
                    }
                    $this->upsertEdge([
                        'edge_id' => $edgeId,
                        'table_a' => $cand['table_a'],
                        'column_a' => $cand['column_a'],
                        'table_b' => $cand['table_b'],
                        'column_b' => $cand['column_b'],
                        'confidence_score' => round($conf, 3),
                        'source' => $cand['source'],
                        'fan_out_risk' => $cand['fan_out_risk'],
                        'cardinality' => $cand['cardinality'],
                        'verified_by' => 'auto',
                        'verified' => false,
                    ]);
                    $seen[$edgeId] = true;
                }
            }
        }

        $after = count($this->latestEdges());
        return [
            'ok' => true,
            'dataset_id' => $ds,
            'edges' => $after,
            'new' => max(0, $after - $before),
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function registerJoin(array $payload): array
    {
        $a = (string) ($payload['table_a'] ?? '');
        $b = (string) ($payload['table_b'] ?? '');
        $ca = (string) ($payload['column_a'] ?? '');
        $cb = (string) ($payload['column_b'] ?? '');
        if ($a === '' || $b === '' || $ca === '' || $cb === '') {
            return ['ok' => false, 'errors' => ['table_a/column_a/table_b/column_b required']];
        }
        $edgeId = (string) ($payload['edge_id'] ?? $this->edgeId($a, $ca, $b, $cb));
        $this->upsertEdge([
            'edge_id' => $edgeId,
            'table_a' => $a,
            'column_a' => $ca,
            'table_b' => $b,
            'column_b' => $cb,
            'confidence_score' => (float) ($payload['confidence_score'] ?? 0.8),
            'source' => (string) ($payload['source'] ?? 'agent'),
            'fan_out_risk' => (string) ($payload['fan_out_risk'] ?? 'medium'),
            'cardinality' => $payload['cardinality'] ?? null,
            'verified_by' => (string) ($payload['verified_by'] ?? 'agent'),
            'verified' => !empty($payload['verified']),
        ]);
        return [
            'ok' => true,
            'edge_id' => $edgeId,
            'dataset_id' => DiscoveryStore::datasetId(),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function latestEdges(): array
    {
        $ds = DiscoveryStore::datasetId();
        $st = DiscoveryStore::pdo()->prepare(
            'SELECT e.* FROM join_edges e
             INNER JOIN (
                SELECT edge_id, MAX(version) AS version FROM join_edges WHERE dataset_id = ? GROUP BY edge_id
             ) x ON e.edge_id = x.edge_id AND e.version = x.version AND e.dataset_id = ?'
        );
        $st->execute([$ds, $ds]);
        return $st->fetchAll() ?: [];
    }

    public function ensureBuilt(): void
    {
        if ($this->latestEdges() === []) {
            $this->rebuild(true);
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function nameMatchCandidates(array $cardA, array $cardB): array
    {
        $out = [];
        $ta = (string) $cardA['table_id'];
        $tb = (string) $cardB['table_id'];
        foreach ($cardA['columns'] ?? [] as $ca) {
            $na = strtolower((string) ($ca['name'] ?? ''));
            $roleA = (string) ($ca['role_guess'] ?? '');
            if ($na === '' || !in_array($roleA, ['key', 'identity', 'attribute'], true)) {
                continue;
            }
            $entA = $this->entities->resolveColumn($na);
            foreach ($cardB['columns'] ?? [] as $cb) {
                $nb = strtolower((string) ($cb['name'] ?? ''));
                $roleB = (string) ($cb['role_guess'] ?? '');
                if ($nb === '' || !in_array($roleB, ['key', 'identity', 'attribute'], true)) {
                    continue;
                }
                $conf = 0.0;
                $source = 'name_match';
                if ($na === $nb) {
                    $conf = 0.75;
                }
                $entB = $this->entities->resolveColumn($nb);
                if ($entA && $entB && ($entA['entity_type'] ?? '') === ($entB['entity_type'] ?? '')) {
                    $conf = max($conf, 0.82);
                    $source = 'canonical';
                }
                if ($conf < 0.7) {
                    continue;
                }
                $cardAPk = strtolower((string) ($cardA['candidate_pk'] ?? ''));
                $cardBPk = strtolower((string) ($cardB['candidate_pk'] ?? ''));
                $fan = 'medium';
                $card = 'N:N';
                if ($nb === $cardBPk || ($cb['cardinality'] ?? 0) >= (($cardB['row_count_approx'] ?? 1) * 0.9)) {
                    $fan = 'low';
                    $card = 'N:1';
                } elseif ($na === $cardAPk) {
                    $fan = 'low';
                    $card = '1:N';
                }
                $out[] = [
                    'table_a' => $ta,
                    'column_a' => $ca['name'],
                    'table_b' => $tb,
                    'column_b' => $cb['name'],
                    'confidence' => $conf,
                    'source' => $source,
                    'fan_out_risk' => $fan,
                    'cardinality' => $card,
                ];
            }
        }
        return $out;
    }

    private function valueOverlap(string $tA, string $cA, string $tB, string $cB): ?float
    {
        foreach ([$tA, $cA, $tB, $cB] as $x) {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $x)) {
                return null;
            }
        }
        try {
            $pdo = Database::pdo();
            $sql = "SELECT COUNT(*) FROM (
                      SELECT DISTINCT a.v FROM (
                        SELECT {$cA} AS v FROM {$tA} WHERE {$cA} IS NOT NULL LIMIT 200
                      ) a
                      INNER JOIN (
                        SELECT DISTINCT {$cB} AS v FROM {$tB} WHERE {$cB} IS NOT NULL LIMIT 200
                      ) b ON a.v = b.v
                    )";
            $overlap = (int) $pdo->query($sql)->fetchColumn();
            $base = (int) $pdo->query(
                "SELECT COUNT(DISTINCT v) FROM (SELECT {$cA} AS v FROM {$tA} WHERE {$cA} IS NOT NULL LIMIT 200)"
            )->fetchColumn();
            if ($base <= 0) {
                return 0.0;
            }
            return min(1.0, $overlap / $base);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string,mixed> $edge */
    private function upsertEdge(array $edge): void
    {
        $ds = DiscoveryStore::datasetId();
        $pdo = DiscoveryStore::pdo();
        $id = $edge['edge_id'];
        $st = $pdo->prepare(
            'SELECT confidence_score, source FROM join_edges
             WHERE dataset_id = ? AND edge_id = ? ORDER BY version DESC LIMIT 1'
        );
        $st->execute([$ds, $id]);
        $prev = $st->fetch();
        if ($prev && abs((float) $prev['confidence_score'] - (float) $edge['confidence_score']) < 0.01
            && (string) $prev['source'] === (string) $edge['source']
        ) {
            return;
        }
        $v = $pdo->prepare('SELECT MAX(version) FROM join_edges WHERE dataset_id = ? AND edge_id = ?');
        $v->execute([$ds, $id]);
        $version = ((int) $v->fetchColumn()) + 1;
        $now = gmdate('c');
        $pdo->prepare(
            'INSERT INTO join_edges (
                edge_id, dataset_id, table_a, column_a, table_b, column_b,
                confidence_score, source, fan_out_risk, cardinality, verified_by, verified,
                usage_count, version, created_at, updated_at
             ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $id,
            $ds,
            $edge['table_a'],
            $edge['column_a'],
            $edge['table_b'],
            $edge['column_b'],
            $edge['confidence_score'],
            $edge['source'],
            $edge['fan_out_risk'],
            $edge['cardinality'] ?? null,
            $edge['verified_by'] ?? 'auto',
            !empty($edge['verified']) ? 1 : 0,
            0,
            $version,
            $now,
            $now,
        ]);
    }

    private function edgeId(string $a, string $ca, string $b, string $cb): string
    {
        $left = strtolower("{$a}.{$ca}");
        $right = strtolower("{$b}.{$cb}");
        if ($left > $right) {
            [$left, $right] = [$right, $left];
            return substr(md5($left . '|' . $right), 0, 12) . '_' . preg_replace('/[^a-z0-9_]/', '_', $b . '_' . $a);
        }
        return substr(md5($left . '|' . $right), 0, 12) . '_' . preg_replace('/[^a-z0-9_]/', '_', $a . '_' . $b);
    }

    private function fanOutFromCardinality(string $card): string
    {
        $c = strtoupper(trim($card));
        return match (true) {
            $c === '1:1', $c === 'N:1', $c === '1:N' => 'low',
            $c === 'N:N', $c === 'M:N' => 'high',
            default => 'medium',
        };
    }

    /**
     * Live probe via ProbeTool when confidence is borderline (planner-internal).
     *
     * @return array<string,mixed>
     */
    public function probeEdge(array $edge): array
    {
        $guard = new SqlGuard(SemanticConfig::allowedTables());
        $probe = new ProbeTool($guard);
        $a = $edge['table_a'];
        $b = $edge['table_b'];
        $ca = $edge['column_a'];
        $cb = $edge['column_b'];
        $sql = "SELECT COUNT(*) AS cnt FROM {$a} JOIN {$b} ON {$a}.{$ca} = {$b}.{$cb}";
        return $probe->probeJoin([], $sql);
    }
}
