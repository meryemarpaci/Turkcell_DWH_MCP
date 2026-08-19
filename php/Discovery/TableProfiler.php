<?php

declare(strict_types=1);

namespace App\Discovery;

use App\SemanticConfig;
use App\Tools\Database;
use PDO;

/**
 * Builds persistent Table Cards: columns, samples, domain/entity heuristics.
 * LLM may refine later via register_table_semantics — profiler stays cheap/SQL-only.
 */
final class TableProfiler
{
    private PiiGuard $pii;

    public function __construct(?PiiGuard $pii = null)
    {
        $this->pii = $pii ?? new PiiGuard();
    }

    /**
     * Profile all allowlisted tables (or given list). Idempotent upsert by version bump only when changed.
     *
     * @param list<string>|null $tables
     * @return array{ok:bool,dataset_id:string,profiled:int,cards:list<array>}
     */
    public function profileAll(?array $tables = null): array
    {
        $ds = DiscoveryStore::datasetId();
        $pdoDwh = Database::pdo();
        $targets = $tables ?? SemanticConfig::allowedTables();
        $cards = [];
        foreach ($targets as $table) {
            $table = (string) $table;
            if ($table === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
                continue;
            }
            $card = $this->profileTable($pdoDwh, $table);
            if ($card !== null) {
                $this->persistCard($card);
                $cards[] = $this->publicCard($card);
            }
        }
        $now = gmdate('c');
        DiscoveryStore::pdo()->prepare(
            'INSERT INTO discovery_meta (dataset_id, last_profiled_at, profile_checksum, updated_at)
             VALUES (?,?,?,?)
             ON CONFLICT(dataset_id) DO UPDATE SET
               last_profiled_at = excluded.last_profiled_at,
               profile_checksum = excluded.profile_checksum,
               updated_at = excluded.updated_at'
        )->execute([$ds, $now, md5(implode(',', array_column($cards, 'table_id'))), $now]);

        return [
            'ok' => true,
            'dataset_id' => $ds,
            'profiled' => count($cards),
            'cards' => $cards,
        ];
    }

    public function ensureProfiled(): void
    {
        $ds = DiscoveryStore::datasetId();
        $st = DiscoveryStore::pdo()->prepare('SELECT last_profiled_at FROM discovery_meta WHERE dataset_id = ?');
        $st->execute([$ds]);
        $row = $st->fetch();
        if ($row && !empty($row['last_profiled_at'])) {
            // Refresh if table count drifted
            $cnt = DiscoveryStore::pdo()->prepare(
                'SELECT COUNT(DISTINCT table_id) FROM table_cards WHERE dataset_id = ?'
            );
            $cnt->execute([$ds]);
            $n = (int) $cnt->fetchColumn();
            if ($n >= count(SemanticConfig::allowedTables())) {
                return;
            }
        }
        $this->profileAll();
    }

    /** @return array<string,mixed>|null */
    private function profileTable(PDO $pdo, string $table): ?array
    {
        try {
            $cols = [];
            $pk = null;
            foreach ($pdo->query("PRAGMA table_info({$table})") as $c) {
                $name = (string) ($c['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                if (!empty($c['pk']) && $pk === null) {
                    $pk = $name;
                }
                $cols[] = [
                    'name' => $name,
                    'type' => (string) ($c['type'] ?? ''),
                    'pk' => !empty($c['pk']),
                ];
            }
            if ($cols === []) {
                return null;
            }

            $rowCount = 0;
            try {
                $rowCount = (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
            } catch (\Throwable) {
            }

            $enriched = [];
            foreach ($cols as $col) {
                $enriched[] = $this->enrichColumn($pdo, $table, $col, $rowCount);
            }

            $guess = $this->guessSemantics($table, $enriched, $pk);
            return [
                'table_id' => $table,
                'dataset_id' => DiscoveryStore::datasetId(),
                'domain' => $guess['domain'],
                'business_entity' => $guess['business_entity'],
                'description' => $guess['description'],
                'candidate_pk' => $pk ?? $guess['candidate_pk'],
                'columns' => $enriched,
                'row_count_approx' => $rowCount,
                'confidence' => $guess['confidence'],
                'verified' => false,
                'created_by' => 'profiler',
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array{name:string,type:string,pk:bool} $col
     * @return array<string,mixed>
     */
    private function enrichColumn(PDO $pdo, string $table, array $col, int $rowCount): array
    {
        $name = $col['name'];
        $type = $col['type'];
        $card = null;
        $nullRate = null;
        $samples = [];
        try {
            $card = (int) $pdo->query("SELECT COUNT(DISTINCT {$name}) FROM {$table}")->fetchColumn();
            if ($rowCount > 0) {
                $nulls = (int) $pdo->query("SELECT COUNT(*) FROM {$table} WHERE {$name} IS NULL")->fetchColumn();
                $nullRate = round($nulls / $rowCount, 4);
            }
            $rows = $pdo->query(
                "SELECT {$name} AS v, COUNT(*) AS n FROM {$table}
                 WHERE {$name} IS NOT NULL GROUP BY 1 ORDER BY n DESC LIMIT 8"
            )->fetchAll() ?: [];
            foreach ($rows as $r) {
                $samples[] = $r['v'];
            }
        } catch (\Throwable) {
        }

        $role = $this->guessColumnRole($name, $type, $card, $samples, !empty($col['pk']));
        return [
            'name' => $name,
            'type' => $type,
            'cardinality' => $card,
            'null_rate' => $nullRate,
            'sample_values' => $this->pii->maskSamples($name, $samples),
            'role_guess' => $role,
            'pii' => $this->pii->isPiiColumn($name),
        ];
    }

    /** @param list<mixed> $samples */
    private function guessColumnRole(string $name, string $type, ?int $card, array $samples, bool $isPk): string
    {
        $n = strtolower($name);
        if ($isPk || $n === 'id' || str_ends_with($n, '_id') || str_ends_with($n, '_key')) {
            return 'key';
        }
        if ($this->pii->isPiiColumn($n)) {
            return 'identity';
        }
        if (preg_match('/(date|time|timestamp|_at|dt)$/i', $n)) {
            return 'temporal';
        }
        $t = strtoupper($type);
        $numeric = str_contains($t, 'INT') || str_contains($t, 'REAL') || str_contains($t, 'NUM') || str_contains($t, 'DEC');
        if ($numeric && ($card === null || $card > 50) && preg_match('/(amount|price|value|qty|total|score|cost|gmv|revenue)/', $n)) {
            return 'measure';
        }
        if ($card !== null && $card <= 50) {
            return 'dimension';
        }
        // Pattern: 10-digit msisdn-like
        $digit10 = 0;
        foreach ($samples as $s) {
            if (is_string($s) && preg_match('/^\d{10,12}$/', $s)) {
                $digit10++;
            }
        }
        if ($digit10 >= 3) {
            return 'identity';
        }
        return $numeric ? 'measure' : 'attribute';
    }

    /**
     * @param list<array<string,mixed>> $columns
     * @return array{domain:string,business_entity:string,description:string,candidate_pk:?string,confidence:float}
     */
    private function guessSemantics(string $table, array $columns, ?string $pk): array
    {
        $t = strtolower($table);
        $domain = 'general';
        $entity = $table;
        $conf = 0.45;

        $rules = [
            '/(crm|customer|subscriber|abone|musteri)/' => ['crm', 'musteri'],
            '/(bill|invoice|fatura|payment|odeme)/' => ['billing', 'fatura'],
            '/(network|cell|tower|sebeke|fault|ariza)/' => ['network', 'ariza'],
            '/(complaint|ticket|sikayet|call_center)/' => ['complaints', 'sikayet'],
            '/(usage|cdr|trafik|session)/' => ['usage', 'kullanim'],
            '/(order|siparis|item)/' => ['commerce', 'siparis'],
            '/(product|urun|catalog)/' => ['catalog', 'urun'],
            '/(seller|vendor|magaza)/' => ['commerce', 'satici'],
            '/(review|rating)/' => ['commerce', 'degerlendirme'],
        ];
        foreach ($rules as $pat => [$d, $e]) {
            if (preg_match($pat, $t)) {
                $domain = $d;
                $entity = $e;
                $conf = 0.7;
                break;
            }
        }
        if (str_starts_with($t, 'fact_')) {
            $domain = $domain === 'general' ? 'facts' : $domain;
            $conf = max($conf, 0.6);
        }
        if (str_starts_with($t, 'dim_')) {
            $domain = $domain === 'general' ? 'dimensions' : $domain;
            $conf = max($conf, 0.65);
        }

        $colNames = array_map(static fn ($c) => strtolower((string) $c['name']), $columns);
        if (in_array('customer_id', $colNames, true) || in_array('msisdn', $colNames, true)) {
            $entity = $entity === $table ? 'musteri' : $entity;
        }

        return [
            'domain' => $domain,
            'business_entity' => $entity,
            'description' => sprintf(
                'Auto profile: domain=%s entity=%s (~%d cols)',
                $domain,
                $entity,
                count($columns)
            ),
            'candidate_pk' => $pk,
            'confidence' => $conf,
        ];
    }

    /** @param array<string,mixed> $card */
    private function persistCard(array $card): void
    {
        $pdo = DiscoveryStore::pdo();
        $ds = $card['dataset_id'];
        $id = $card['table_id'];
        $st = $pdo->prepare(
            'SELECT columns_json, domain, business_entity FROM table_cards
             WHERE dataset_id = ? AND table_id = ? ORDER BY version DESC LIMIT 1'
        );
        $st->execute([$ds, $id]);
        $prev = $st->fetch();
        $colsJson = json_encode($card['columns'], JSON_UNESCAPED_UNICODE);
        if ($prev
            && (string) $prev['columns_json'] === $colsJson
            && (string) $prev['domain'] === (string) $card['domain']
            && (string) $prev['business_entity'] === (string) $card['business_entity']
        ) {
            return;
        }
        $version = 1;
        if ($prev) {
            $v = $pdo->prepare('SELECT MAX(version) FROM table_cards WHERE dataset_id = ? AND table_id = ?');
            $v->execute([$ds, $id]);
            $version = ((int) $v->fetchColumn()) + 1;
        }
        $now = gmdate('c');
        $pdo->prepare(
            'INSERT INTO table_cards (
                table_id, dataset_id, domain, business_entity, description, candidate_pk,
                columns_json, row_count_approx, confidence, verified, created_by, version, created_at, updated_at
             ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $id,
            $ds,
            $card['domain'],
            $card['business_entity'],
            $card['description'],
            $card['candidate_pk'],
            $colsJson,
            $card['row_count_approx'],
            $card['confidence'],
            0,
            $card['created_by'],
            $version,
            $now,
            $now,
        ]);
    }

    /**
     * Agent-facing register/update of semantics (does not re-sample).
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function registerSemantics(array $payload): array
    {
        $ds = DiscoveryStore::datasetId();
        $id = strtolower(trim((string) ($payload['table_id'] ?? '')));
        if ($id === '') {
            return ['ok' => false, 'errors' => ['table_id required']];
        }
        $this->ensureProfiled();
        $existing = $this->getCard($id);
        if ($existing === null) {
            // Force profile this table first
            $this->profileAll([$id]);
            $existing = $this->getCard($id);
        }
        if ($existing === null) {
            return ['ok' => false, 'errors' => ["Unknown table {$id}"]];
        }
        $card = $existing;
        $card['domain'] = $payload['domain'] ?? $card['domain'];
        $card['business_entity'] = $payload['business_entity'] ?? $card['business_entity'];
        $card['description'] = $payload['description'] ?? $card['description'];
        $card['candidate_pk'] = $payload['candidate_pk'] ?? $card['candidate_pk'];
        $card['confidence'] = isset($payload['confidence']) ? (float) $payload['confidence'] : max(0.8, (float) $card['confidence']);
        $card['verified'] = !empty($payload['verified']);
        $card['created_by'] = 'agent';
        $card['dataset_id'] = $ds;
        $card['table_id'] = $id;

        $pdo = DiscoveryStore::pdo();
        $v = $pdo->prepare('SELECT MAX(version) FROM table_cards WHERE dataset_id = ? AND table_id = ?');
        $v->execute([$ds, $id]);
        $version = ((int) $v->fetchColumn()) + 1;
        $now = gmdate('c');
        $pdo->prepare(
            'INSERT INTO table_cards (
                table_id, dataset_id, domain, business_entity, description, candidate_pk,
                columns_json, row_count_approx, confidence, verified, created_by, version, created_at, updated_at
             ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $id,
            $ds,
            $card['domain'],
            $card['business_entity'],
            $card['description'],
            $card['candidate_pk'],
            json_encode($card['columns'] ?? [], JSON_UNESCAPED_UNICODE),
            $card['row_count_approx'] ?? null,
            $card['confidence'],
            $card['verified'] ? 1 : 0,
            'agent',
            $version,
            $now,
            $now,
        ]);
        return ['ok' => true, 'table_id' => $id, 'version' => $version, 'dataset_id' => $ds];
    }

    /** @return array<string,mixed>|null */
    public function getCard(string $tableId): ?array
    {
        $ds = DiscoveryStore::datasetId();
        $st = DiscoveryStore::pdo()->prepare(
            'SELECT * FROM table_cards WHERE dataset_id = ? AND table_id = ?
             ORDER BY version DESC LIMIT 1'
        );
        $st->execute([$ds, $tableId]);
        $row = $st->fetch();
        if (!$row) {
            foreach ($this->latestCards() as $c) {
                if (strcasecmp((string) $c['table_id'], $tableId) === 0) {
                    $row = $c;
                    break;
                }
            }
        }
        if (!is_array($row)) {
            return null;
        }
        $row['columns'] = json_decode((string) ($row['columns_json'] ?? '[]'), true) ?: [];
        $row['verified'] = !empty($row['verified']);
        return $row;
    }

    /** @return list<array<string,mixed>> */
    public function latestCards(): array
    {
        $ds = DiscoveryStore::datasetId();
        $st = DiscoveryStore::pdo()->prepare(
            'SELECT t.* FROM table_cards t
             INNER JOIN (
                SELECT table_id, MAX(version) AS version FROM table_cards WHERE dataset_id = ? GROUP BY table_id
             ) x ON t.table_id = x.table_id AND t.version = x.version AND t.dataset_id = ?'
        );
        $st->execute([$ds, $ds]);
        $out = [];
        foreach ($st->fetchAll() ?: [] as $row) {
            $row['columns'] = json_decode((string) ($row['columns_json'] ?? '[]'), true) ?: [];
            $row['verified'] = !empty($row['verified']);
            $out[] = $row;
        }
        return $out;
    }

    /** Compact card for LLM (PII already masked in samples). */
    public function publicCard(array $card): array
    {
        $cols = [];
        foreach ($card['columns'] ?? [] as $c) {
            $cols[] = [
                'name' => $c['name'] ?? '',
                'type' => $c['type'] ?? '',
                'cardinality' => $c['cardinality'] ?? null,
                'role_guess' => $c['role_guess'] ?? null,
                'sample_values' => array_slice($c['sample_values'] ?? [], 0, 5),
                'pii' => !empty($c['pii']),
            ];
        }
        return [
            'table_id' => $card['table_id'],
            'domain' => $card['domain'],
            'business_entity' => $card['business_entity'],
            'description' => $card['description'],
            'candidate_pk' => $card['candidate_pk'],
            'row_count_approx' => $card['row_count_approx'] ?? null,
            'confidence' => $card['confidence'],
            'verified' => !empty($card['verified']),
            'columns' => $cols,
        ];
    }
}
