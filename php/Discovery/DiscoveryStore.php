<?php

declare(strict_types=1);

namespace App\Discovery;

use App\Semantic\RegistryStore;
use PDO;

/**
 * Persist layer for multi-domain discovery: table cards, canonical keys, join graph.
 * Shares the writable registry SQLite (never the read-only DWH).
 */
final class DiscoveryStore
{
    public static function pdo(): PDO
    {
        $pdo = RegistryStore::pdo();
        self::migrate($pdo);
        return $pdo;
    }

    public static function datasetId(): string
    {
        return RegistryStore::datasetId();
    }

    private static function migrate(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS table_cards (
    table_id TEXT NOT NULL,
    dataset_id TEXT NOT NULL,
    domain TEXT,
    business_entity TEXT,
    description TEXT,
    candidate_pk TEXT,
    columns_json TEXT NOT NULL,
    row_count_approx INTEGER,
    confidence REAL DEFAULT 0.5,
    verified INTEGER DEFAULT 0,
    created_by TEXT DEFAULT 'profiler',
    version INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    PRIMARY KEY (dataset_id, table_id, version)
);
CREATE TABLE IF NOT EXISTS canonical_entities (
    entity_type TEXT NOT NULL,
    dataset_id TEXT NOT NULL,
    aliases_json TEXT NOT NULL,
    value_pattern TEXT,
    description TEXT,
    confidence REAL DEFAULT 0.5,
    verified INTEGER DEFAULT 0,
    created_by TEXT DEFAULT 'agent',
    version INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    PRIMARY KEY (dataset_id, entity_type, version)
);
CREATE TABLE IF NOT EXISTS join_edges (
    edge_id TEXT NOT NULL,
    dataset_id TEXT NOT NULL,
    table_a TEXT NOT NULL,
    column_a TEXT NOT NULL,
    table_b TEXT NOT NULL,
    column_b TEXT NOT NULL,
    confidence_score REAL NOT NULL DEFAULT 0.5,
    source TEXT NOT NULL,
    fan_out_risk TEXT DEFAULT 'medium',
    cardinality TEXT,
    verified_by TEXT DEFAULT 'auto',
    verified INTEGER DEFAULT 0,
    usage_count INTEGER DEFAULT 0,
    version INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    PRIMARY KEY (dataset_id, edge_id, version)
);
CREATE TABLE IF NOT EXISTS join_paths (
    path_id TEXT NOT NULL,
    dataset_id TEXT NOT NULL,
    tables_json TEXT NOT NULL,
    edges_json TEXT NOT NULL,
    confidence_score REAL,
    fan_out_risk TEXT,
    sql_fragment TEXT,
    usage_count INTEGER DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    PRIMARY KEY (dataset_id, path_id)
);
CREATE TABLE IF NOT EXISTS discovery_meta (
    dataset_id TEXT PRIMARY KEY,
    last_profiled_at TEXT,
    profile_checksum TEXT,
    updated_at TEXT NOT NULL
);
SQL);
    }
}
