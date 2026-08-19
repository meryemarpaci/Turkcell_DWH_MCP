<?php

declare(strict_types=1);

namespace App\Semantic;

use App\DatasetProfile;
use App\SemanticConfig;
use App\Tools\Database;
use App\Tools\DimensionCatalog;
use PDO;

/**
 * Read/write semantic registry + resolve metric/dimension ids for analyze_* tools.
 */
final class RegistryService
{
    public function __construct(private readonly ?string $datasetId = null)
    {
    }

    public function datasetId(): string
    {
        return $this->datasetId ?? RegistryStore::datasetId();
    }

    public function ensureSeededFromProfile(): void
    {
        $pdo = RegistryStore::pdo();
        $ds = $this->datasetId();
        $st = $pdo->prepare('SELECT seeded_from_profile FROM registry_meta WHERE dataset_id = ?');
        $st->execute([$ds]);
        $row = $st->fetch();
        if ($row && (int) ($row['seeded_from_profile'] ?? 0) === 1) {
            return;
        }

        $now = gmdate('c');
        foreach (SemanticConfig::metrics() as $m) {
            $id = strtolower(trim((string) ($m['id'] ?? '')));
            if ($id === '') {
                continue;
            }
            $this->registerMetric([
                'metric_id' => $id,
                'expression' => (string) ($m['expression'] ?? ''),
                'description' => (string) ($m['description'] ?? ''),
                'grain' => (string) ($m['grain'] ?? ''),
                'aggregation' => $this->inferAgg((string) ($m['expression'] ?? '')),
                'created_by' => 'profile_seed',
                'verified' => true,
            ], false);
        }

        // Prefer explicit profile dimensions; else catalog heuristics
        $rawDims = DatasetProfile::get()['dimensions'] ?? [];
        if (is_array($rawDims) && $rawDims !== []) {
            foreach ($rawDims as $d) {
                if (!is_array($d)) {
                    continue;
                }
                $id = strtolower(trim((string) ($d['id'] ?? '')));
                if ($id === '') {
                    continue;
                }
                $this->registerDimension([
                    'dimension_id' => $id,
                    'expr' => (string) ($d['expr'] ?? ''),
                    'source_column' => $this->columnFromExpr((string) ($d['expr'] ?? '')),
                    'tables' => $d['tables'] ?? [],
                    'joins' => $d['joins'] ?? [],
                    'entity' => !empty($d['entity']),
                    'description' => (string) ($d['description'] ?? ''),
                    'created_by' => 'profile_seed',
                    'verified' => true,
                ], false);
            }
        } else {
            foreach (DimensionCatalog::all() as $id => $meta) {
                $this->registerDimension([
                    'dimension_id' => $id,
                    'expr' => (string) ($meta['expr'] ?? ''),
                    'tables' => $meta['tables'] ?? [],
                    'joins' => $meta['joins'] ?? [],
                    'entity' => !empty($meta['entity']),
                    'created_by' => 'catalog_seed',
                    'verified' => false,
                ], false);
            }
        }

        $pdo->prepare(
            'INSERT INTO registry_meta (dataset_id, schema_checksum, seeded_from_profile, updated_at)
             VALUES (?, ?, 1, ?)
             ON CONFLICT(dataset_id) DO UPDATE SET seeded_from_profile = 1, updated_at = excluded.updated_at'
        )->execute([$ds, '', $now]);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function registerMetric(array $payload, bool $bumpVersion = true): array
    {
        $ds = $this->datasetId();
        $id = strtolower(trim((string) ($payload['metric_id'] ?? '')));
        $expr = trim((string) ($payload['expression'] ?? ''));
        if ($id === '' || $expr === '') {
            return ['ok' => false, 'errors' => ['metric_id and expression required']];
        }
        $pdo = RegistryStore::pdo();
        $version = 1;
        if ($bumpVersion) {
            $st = $pdo->prepare('SELECT MAX(version) AS v FROM metrics WHERE dataset_id = ? AND metric_id = ?');
            $st->execute([$ds, $id]);
            $version = ((int) ($st->fetch()['v'] ?? 0)) + 1;
        } else {
            $st = $pdo->prepare('SELECT 1 FROM metrics WHERE dataset_id = ? AND metric_id = ? LIMIT 1');
            $st->execute([$ds, $id]);
            if ($st->fetch()) {
                return ['ok' => true, 'metric_id' => $id, 'skipped' => true, 'reason' => 'already exists'];
            }
        }
        $now = gmdate('c');
        $pdo->prepare(
            'INSERT INTO metrics (
                metric_id, dataset_id, expression, source_column, aggregation, grain,
                description, created_by, verified, version, created_at, updated_at
             ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $id,
            $ds,
            $expr,
            $payload['source_column'] ?? null,
            $payload['aggregation'] ?? $this->inferAgg($expr),
            $payload['grain'] ?? null,
            $payload['description'] ?? null,
            $payload['created_by'] ?? 'agent',
            !empty($payload['verified']) ? 1 : 0,
            $version,
            $now,
            $now,
        ]);
        return [
            'ok' => true,
            'metric_id' => $id,
            'version' => $version,
            'dataset_id' => $ds,
            'expression' => $expr,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function registerDimension(array $payload, bool $bumpVersion = true): array
    {
        $ds = $this->datasetId();
        $id = strtolower(trim((string) ($payload['dimension_id'] ?? '')));
        $expr = trim((string) ($payload['expr'] ?? $payload['expression'] ?? ''));
        if ($id === '' || $expr === '') {
            return ['ok' => false, 'errors' => ['dimension_id and expr required']];
        }

        // Merge with previous registry / catalog so agent cannot wipe join bridges.
        $prev = $this->fetchDimensionRow($id);
        if ($prev === null) {
            $prev = DimensionCatalog::get($id);
        }
        $tables = $payload['tables'] ?? [];
        $joins = $payload['joins'] ?? [];
        if (!is_array($tables)) {
            $tables = [];
        }
        if (!is_array($joins)) {
            $joins = [];
        }
        if ($tables === [] && is_array($prev)) {
            $tables = $prev['tables'] ?? [];
        }
        if ($joins === [] && is_array($prev)) {
            $joins = $prev['joins'] ?? [];
        }
        if ($tables === []) {
            $fb = DimensionCatalog::get($id);
            if ($fb !== null) {
                $tables = $fb['tables'] ?? [];
                if ($joins === []) {
                    $joins = $fb['joins'] ?? [];
                }
            }
        }
        if ($tables === [] && preg_match('/^([a-zA-Z0-9_]+)\./', $expr, $m)) {
            $tables = [$m[1]];
        }

        $pdo = RegistryStore::pdo();
        $version = 1;
        if ($bumpVersion) {
            $st = $pdo->prepare('SELECT MAX(version) AS v FROM dimensions WHERE dataset_id = ? AND dimension_id = ?');
            $st->execute([$ds, $id]);
            $version = ((int) ($st->fetch()['v'] ?? 0)) + 1;
        } else {
            $st = $pdo->prepare('SELECT 1 FROM dimensions WHERE dataset_id = ? AND dimension_id = ? LIMIT 1');
            $st->execute([$ds, $id]);
            if ($st->fetch()) {
                return ['ok' => true, 'dimension_id' => $id, 'skipped' => true, 'reason' => 'already exists'];
            }
        }
        $now = gmdate('c');
        $pdo->prepare(
            'INSERT INTO dimensions (
                dimension_id, dataset_id, expr, source_column, join_path, tables_json, joins_json,
                cardinality, type, description, entity, created_by, verified, version, created_at, updated_at
             ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $id,
            $ds,
            $expr,
            $payload['source_column'] ?? $this->columnFromExpr($expr),
            $payload['join_path'] ?? null,
            json_encode(array_values($tables), JSON_UNESCAPED_UNICODE),
            json_encode(array_values($joins), JSON_UNESCAPED_UNICODE),
            $payload['cardinality'] ?? null,
            $payload['type'] ?? null,
            $payload['description'] ?? null,
            !empty($payload['entity']) ? 1 : 0,
            $payload['created_by'] ?? 'agent',
            !empty($payload['verified']) ? 1 : 0,
            $version,
            $now,
            $now,
        ]);
        return [
            'ok' => true,
            'dimension_id' => $id,
            'version' => $version,
            'dataset_id' => $ds,
            'expr' => $expr,
            'tables' => array_values($tables),
            'joins' => array_values($joins),
        ];
    }

    /** Latest metric definition or null. */
    public function resolveMetric(string $metricId): ?array
    {
        $this->ensureSeededFromProfile();
        $id = strtolower(trim($metricId));
        // aliases
        $id = match ($id) {
            'sales', 'revenue', 'ciro' => 'gmv',
            'orders', 'siparis', 'sipariş' => 'order_count',
            'freight', 'kargo' => 'freight_total',
            'reviews', 'score' => 'avg_review_score',
            'payments' => 'payment_value',
            'delivery', 'delivery_days' => 'avg_delivery_days',
            default => $id,
        };
        $pdo = RegistryStore::pdo();
        $st = $pdo->prepare(
            'SELECT * FROM metrics WHERE dataset_id = ? AND metric_id = ?
             ORDER BY version DESC LIMIT 1'
        );
        $st->execute([$this->datasetId(), $id]);
        $row = $st->fetch();
        return is_array($row) ? $row : null;
    }

    /** Latest dimension definition or null. */
    public function resolveDimension(string $dimensionId): ?array
    {
        $this->ensureSeededFromProfile();
        $id = strtolower(trim($dimensionId));
        if ($id === '') {
            return null;
        }

        // Common language / physical-column aliases → registry id
        $id = match ($id) {
            'category_name_english', 'product_category', 'product_category_name',
            'kategori', 'category_name' => 'category',
            'state', 'eyalet', 'il' => 'customer_state',
            'city', 'sehir', 'şehir' => 'customer_city',
            'magaza', 'mağaza', 'store', 'satici', 'satıcı', 'seller' => 'seller_id',
            'urun', 'ürün', 'product' => 'product_id',
            'musteri', 'müşteri', 'customer' => 'customer_id',
            default => $id,
        };

        $row = $this->fetchDimensionRow($id);
        if ($row !== null) {
            return $this->enrichDimensionFromCatalog($row, $id);
        }

        // Physical column / expr suffix: dim_product.category_name_english ← category_name_english
        $row = $this->fetchDimensionByColumn($id);
        if ($row !== null) {
            return $this->enrichDimensionFromCatalog($row, $id);
        }

        // Fallback alias via DimensionCatalog (migration bridge)
        return DimensionCatalog::get($id);
    }

    /**
     * Fill missing tables/joins from catalog when an agent register overwrote a thin version.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function enrichDimensionFromCatalog(array $row, string $requestedId): array
    {
        $tables = $row['tables'] ?? [];
        $joins = $row['joins'] ?? [];
        if ((!is_array($tables) || $tables === []) || (!is_array($joins) || $joins === [])) {
            $fb = DimensionCatalog::get((string) ($row['dimension_id'] ?? $requestedId));
            if ($fb !== null) {
                if (!is_array($tables) || $tables === []) {
                    $row['tables'] = $fb['tables'] ?? [];
                }
                if (!is_array($joins) || $joins === []) {
                    $row['joins'] = $fb['joins'] ?? [];
                }
                if (trim((string) ($row['expr'] ?? '')) === '' && !empty($fb['expr'])) {
                    $row['expr'] = $fb['expr'];
                }
            }
        }
        return $row;
    }

    /** @return array<string,mixed>|null */
    private function fetchDimensionRow(string $id): ?array
    {
        $pdo = RegistryStore::pdo();
        $st = $pdo->prepare(
            'SELECT * FROM dimensions WHERE dataset_id = ? AND dimension_id = ?
             ORDER BY version DESC LIMIT 1'
        );
        $st->execute([$this->datasetId(), $id]);
        $row = $st->fetch();
        if (!is_array($row)) {
            return null;
        }
        $row['tables'] = json_decode((string) ($row['tables_json'] ?? '[]'), true) ?: [];
        $row['joins'] = json_decode((string) ($row['joins_json'] ?? '[]'), true) ?: [];
        $row['alias'] = $row['dimension_id'];
        $row['entity'] = !empty($row['entity']);
        return $row;
    }

    /**
     * Resolve by source_column or trailing column of expr (agent often passes physical names).
     *
     * @return array<string,mixed>|null
     */
    private function fetchDimensionByColumn(string $columnOrExpr): ?array
    {
        $needle = $columnOrExpr;
        if (str_contains($needle, '.')) {
            $parts = explode('.', $needle);
            $needle = strtolower(trim((string) end($parts)));
        }
        if ($needle === '') {
            return null;
        }

        $pdo = RegistryStore::pdo();
        $ds = $this->datasetId();
        $st = $pdo->prepare(
            'SELECT d.* FROM dimensions d
             INNER JOIN (
                SELECT dimension_id, MAX(version) AS version FROM dimensions WHERE dataset_id = ? GROUP BY dimension_id
             ) latest ON d.dimension_id = latest.dimension_id AND d.version = latest.version AND d.dataset_id = ?'
        );
        $st->execute([$ds, $ds]);
        $best = null;
        foreach ($st->fetchAll() ?: [] as $row) {
            $source = strtolower(trim((string) ($row['source_column'] ?? '')));
            $expr = strtolower(trim((string) ($row['expr'] ?? '')));
            $dimId = strtolower((string) ($row['dimension_id'] ?? ''));
            $match = false;
            if ($source !== '' && ($source === $needle || str_ends_with($source, '.' . $needle))) {
                $match = true;
            } elseif ($expr === $needle || str_ends_with($expr, '.' . $needle)) {
                $match = true;
            } elseif ($dimId === $needle) {
                $match = true;
            }
            if ($match) {
                // Prefer exact dimension_id == needle, else first expr match
                if ($dimId === $needle) {
                    $best = $row;
                    break;
                }
                $best ??= $row;
            }
        }
        if (!is_array($best)) {
            return null;
        }
        $best['tables'] = json_decode((string) ($best['tables_json'] ?? '[]'), true) ?: [];
        $best['joins'] = json_decode((string) ($best['joins_json'] ?? '[]'), true) ?: [];
        $best['alias'] = $best['dimension_id'];
        $best['entity'] = !empty($best['entity']);
        return $best;
    }

    /**
     * Semantic-ish search over registry (substring / token match). Caps results.
     *
     * @return array{ok:bool,dataset_id:string,metrics:list<array>,dimensions:list<array>}
     */
    public function search(string $query, int $limit = 15): array
    {
        $this->ensureSeededFromProfile();
        $ds = $this->datasetId();
        $q = mb_strtolower(trim($query));
        // Agents often pass "a, b, c" — score each token separately
        $q = str_replace([',', ';', '|'], ' ', $q);
        $q = trim(preg_replace('/\s+/', ' ', $q) ?? $q);
        $pdo = RegistryStore::pdo();

        $metrics = $pdo->prepare(
            'SELECT m.* FROM metrics m
             INNER JOIN (
                SELECT metric_id, MAX(version) AS version FROM metrics WHERE dataset_id = ? GROUP BY metric_id
             ) latest ON m.metric_id = latest.metric_id AND m.version = latest.version AND m.dataset_id = ?
             ORDER BY m.metric_id'
        );
        $metrics->execute([$ds, $ds]);
        $allM = $metrics->fetchAll() ?: [];

        $dims = $pdo->prepare(
            'SELECT d.* FROM dimensions d
             INNER JOIN (
                SELECT dimension_id, MAX(version) AS version FROM dimensions WHERE dataset_id = ? GROUP BY dimension_id
             ) latest ON d.dimension_id = latest.dimension_id AND d.version = latest.version AND d.dataset_id = ?
             ORDER BY d.dimension_id'
        );
        $dims->execute([$ds, $ds]);
        $allD = $dims->fetchAll() ?: [];

        $score = static function (array $row, string $q, array $fields): int {
            if ($q === '') {
                return 1;
            }
            $hay = mb_strtolower(implode(' ', array_map(
                static fn ($f) => (string) ($row[$f] ?? ''),
                $fields
            )));
            if ($hay === '') {
                return 0;
            }
            if ($hay === $q || str_contains($hay, $q)) {
                return 10;
            }
            $tokens = preg_split('/\s+/', $q) ?: [];
            $s = 0;
            foreach ($tokens as $t) {
                if ($t !== '' && str_contains($hay, $t)) {
                    $s += 3;
                }
            }
            return $s;
        };

        $mScored = [];
        foreach ($allM as $row) {
            $s = $score($row, $q, ['metric_id', 'description', 'expression', 'aggregation']);
            if ($s > 0) {
                $mScored[] = ['score' => $s, 'row' => [
                    'metric_id' => $row['metric_id'],
                    'description' => $row['description'],
                    'aggregation' => $row['aggregation'],
                    'grain' => $row['grain'],
                    'verified' => (bool) $row['verified'],
                    'version' => (int) $row['version'],
                ]];
            }
        }
        usort($mScored, static fn ($a, $b) => $b['score'] <=> $a['score']);

        $dScored = [];
        foreach ($allD as $row) {
            $s = $score($row, $q, ['dimension_id', 'description', 'expr', 'type']);
            if ($s > 0) {
                $dScored[] = ['score' => $s, 'row' => [
                    'dimension_id' => $row['dimension_id'],
                    'description' => $row['description'],
                    'type' => $row['type'],
                    'entity' => (bool) $row['entity'],
                    'verified' => (bool) $row['verified'],
                    'version' => (int) $row['version'],
                ]];
            }
        }
        usort($dScored, static fn ($a, $b) => $b['score'] <=> $a['score']);

        $limit = max(1, min(30, $limit));
        return [
            'ok' => true,
            'dataset_id' => $ds,
            'query' => $query,
            'metrics' => array_map(static fn ($x) => $x['row'], array_slice($mScored, 0, $limit)),
            'dimensions' => array_map(static fn ($x) => $x['row'], array_slice($dScored, 0, $limit)),
        ];
    }

    /** Sample values for a physical column (discovery). */
    public function describeColumn(string $table, string $column, int $limit = 8): array
    {
        $allowed = array_map('strtolower', SemanticConfig::allowedTables());
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table) ?? '';
        $column = preg_replace('/[^a-zA-Z0-9_]/', '', $column) ?? '';
        if ($table === '' || $column === '' || !in_array(strtolower($table), $allowed, true)) {
            return ['ok' => false, 'errors' => ['table/column not allowlisted']];
        }
        try {
            $pdo = Database::pdo();
            $type = null;
            foreach ($pdo->query("PRAGMA table_info({$table})") as $c) {
                if (strcasecmp((string) $c['name'], $column) === 0) {
                    $type = (string) ($c['type'] ?? '');
                    break;
                }
            }
            if ($type === null) {
                return ['ok' => false, 'errors' => ["column {$table}.{$column} not found"]];
            }
            $limit = max(1, min(20, $limit));
            $sql = "SELECT {$column} AS v, COUNT(*) AS n FROM {$table}
                    WHERE {$column} IS NOT NULL
                    GROUP BY 1 ORDER BY n DESC LIMIT {$limit}";
            $samples = $pdo->query($sql)->fetchAll() ?: [];
            $cnt = (int) $pdo->query("SELECT COUNT(DISTINCT {$column}) FROM {$table}")->fetchColumn();
            $suggestion = $this->suggestRole($type, $cnt, $samples, $column);
            return [
                'ok' => true,
                'dataset_id' => $this->datasetId(),
                'table' => $table,
                'column' => $column,
                'type' => $type,
                'approx_cardinality' => $cnt,
                'samples' => $samples,
                'suggestion' => $suggestion,
                'note' => 'Use register_metric or register_dimension once, then analyze_* with the id.',
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'errors' => [$e->getMessage()]];
        }
    }

    /** @param list<array<string,mixed>> $samples */
    private function suggestRole(string $type, int $cardinality, array $samples, string $column): array
    {
        $t = strtoupper($type);
        $numeric = str_contains($t, 'INT') || str_contains($t, 'REAL') || str_contains($t, 'NUM') || str_contains($t, 'DEC');
        $name = strtolower($column);
        if ($numeric && ($cardinality > 50 || preg_match('/(amount|price|value|qty|quantity|score|total|gmv|cost)/', $name))) {
            return [
                'kind' => 'metric',
                'aggregation' => 'SUM',
                'proposed_metric_id' => preg_replace('/[^a-z0-9_]/', '_', $name) ?: 'metric',
                'proposed_expression' => null, // caller fills table.column
            ];
        }
        return [
            'kind' => 'dimension',
            'proposed_dimension_id' => preg_replace('/[^a-z0-9_]/', '_', $name) ?: 'dim',
            'entity' => str_ends_with($name, '_id'),
        ];
    }

    private function inferAgg(string $expr): string
    {
        if (preg_match('/\b(SUM|AVG|COUNT|MIN|MAX|TOTAL)\s*\(/i', $expr, $m)) {
            return strtoupper($m[1]);
        }
        return 'EXPR';
    }

    private function columnFromExpr(string $expr): ?string
    {
        $expr = trim($expr);
        if ($expr === '') {
            return null;
        }
        if (preg_match('/([a-zA-Z0-9_]+)\s*$/', $expr, $m)) {
            $col = $m[1];
            // skip bare function names
            if (!preg_match('/^(SUM|AVG|COUNT|MIN|MAX)$/i', $col)) {
                return strtolower($col);
            }
        }
        return null;
    }
}
