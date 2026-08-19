<?php

declare(strict_types=1);

namespace App\Discovery;

/**
 * Canonical entity layer: same business key under many column names (msisdn, customer_no, …).
 */
final class EntityResolver
{
    /** Seed aliases commonly seen in telco / commerce DWH. */
    private const BOOTSTRAP = [
        'musteri' => [
            'aliases' => ['customer_id', 'customer_no', 'cust_id', 'msisdn', 'sub_id', 'subscriber_id', 'abone_no', 'abone_id'],
            'pattern' => null,
        ],
        'abonelik' => [
            'aliases' => ['subscription_id', 'sub_id', 'abonelik_id', 'line_id'],
            'pattern' => null,
        ],
        'fatura' => [
            'aliases' => ['invoice_id', 'bill_id', 'fatura_no', 'fatura_id'],
            'pattern' => null,
        ],
        'siparis' => [
            'aliases' => ['order_id', 'siparis_id', 'order_no'],
            'pattern' => null,
        ],
        'urun' => [
            'aliases' => ['product_id', 'urun_id', 'sku', 'item_id'],
            'pattern' => null,
        ],
        'satici' => [
            'aliases' => ['seller_id', 'vendor_id', 'satici_id', 'store_id'],
            'pattern' => null,
        ],
    ];

    public function ensureSeeded(): void
    {
        $pdo = DiscoveryStore::pdo();
        $ds = DiscoveryStore::datasetId();
        $st = $pdo->prepare('SELECT 1 FROM canonical_entities WHERE dataset_id = ? LIMIT 1');
        $st->execute([$ds]);
        if ($st->fetch()) {
            return;
        }
        foreach (self::BOOTSTRAP as $type => $meta) {
            $this->register($type, $meta['aliases'], $meta['pattern'], 'bootstrap', 0.55, false);
        }
    }

    /**
     * @param list<string> $aliases
     * @return array<string,mixed>
     */
    public function register(
        string $entityType,
        array $aliases,
        ?string $pattern = null,
        string $createdBy = 'agent',
        float $confidence = 0.7,
        bool $verified = false,
        ?string $description = null
    ): array {
        $ds = DiscoveryStore::datasetId();
        $type = strtolower(trim($entityType));
        $aliases = array_values(array_unique(array_filter(array_map(
            static fn ($a) => strtolower(trim((string) $a)),
            $aliases
        ))));
        if ($type === '' || $aliases === []) {
            return ['ok' => false, 'errors' => ['entity_type and aliases required']];
        }
        $pdo = DiscoveryStore::pdo();
        $st = $pdo->prepare('SELECT MAX(version) AS v FROM canonical_entities WHERE dataset_id = ? AND entity_type = ?');
        $st->execute([$ds, $type]);
        $version = ((int) ($st->fetch()['v'] ?? 0)) + 1;
        $now = gmdate('c');
        $pdo->prepare(
            'INSERT INTO canonical_entities (
                entity_type, dataset_id, aliases_json, value_pattern, description,
                confidence, verified, created_by, version, created_at, updated_at
             ) VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $type,
            $ds,
            json_encode($aliases, JSON_UNESCAPED_UNICODE),
            $pattern,
            $description,
            $confidence,
            $verified ? 1 : 0,
            $createdBy,
            $version,
            $now,
            $now,
        ]);
        return [
            'ok' => true,
            'entity_type' => $type,
            'aliases' => $aliases,
            'version' => $version,
            'dataset_id' => $ds,
        ];
    }

    /** Map a physical column name → canonical entity_type or null. */
    public function resolveColumn(string $column): ?array
    {
        $this->ensureSeeded();
        $col = strtolower(trim($column));
        foreach ($this->latestEntities() as $e) {
            $aliases = $e['aliases'] ?? [];
            if (in_array($col, $aliases, true)) {
                return $e;
            }
            // fuzzy: column contains alias or vice versa
            foreach ($aliases as $a) {
                if ($a !== '' && (str_contains($col, $a) || str_contains($a, $col))) {
                    return $e + ['_fuzzy' => true];
                }
            }
        }
        return null;
    }

    /** @return list<array<string,mixed>> */
    public function latestEntities(): array
    {
        $this->ensureSeeded();
        $ds = DiscoveryStore::datasetId();
        $st = DiscoveryStore::pdo()->prepare(
            'SELECT e.* FROM canonical_entities e
             INNER JOIN (
                SELECT entity_type, MAX(version) AS version FROM canonical_entities WHERE dataset_id = ? GROUP BY entity_type
             ) x ON e.entity_type = x.entity_type AND e.version = x.version AND e.dataset_id = ?'
        );
        $st->execute([$ds, $ds]);
        $out = [];
        foreach ($st->fetchAll() ?: [] as $row) {
            $row['aliases'] = json_decode((string) ($row['aliases_json'] ?? '[]'), true) ?: [];
            $row['verified'] = !empty($row['verified']);
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Infer new aliases from table cards (role=key/identity) and merge into entities.
     *
     * @return array{ok:bool,updated:int}
     */
    public function enrichFromCards(TableProfiler $profiler): array
    {
        $this->ensureSeeded();
        $updated = 0;
        foreach ($profiler->latestCards() as $card) {
            foreach ($card['columns'] ?? [] as $col) {
                $role = (string) ($col['role_guess'] ?? '');
                $name = strtolower((string) ($col['name'] ?? ''));
                if ($name === '' || !in_array($role, ['key', 'identity'], true)) {
                    continue;
                }
                $hit = $this->resolveColumn($name);
                if ($hit !== null) {
                    continue;
                }
                // New key → create entity from column stem
                $stem = preg_replace('/_?(id|no|key|num)$/', '', $name) ?: $name;
                $this->register($stem, [$name], null, 'auto_enrich', 0.5, false);
                $updated++;
            }
        }
        return ['ok' => true, 'updated' => $updated];
    }
}
