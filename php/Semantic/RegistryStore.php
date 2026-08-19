<?php

declare(strict_types=1);

namespace App\Semantic;

use App\DatasetProfile;
use PDO;
use RuntimeException;

/**
 * Versioned semantic registry (metrics / dimensions / schema_state).
 * Stored in a writable SQLite file — never the read-only DWH.
 */
final class RegistryStore
{
    private static ?PDO $pdo = null;
    private static ?string $overrideDatasetId = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        $dir = APP_ROOT . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'registry';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $path = $dir . DIRECTORY_SEPARATOR . 'semantic_registry.sqlite';
        self::$pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        self::migrate(self::$pdo);
        return self::$pdo;
    }

    private static function migrate(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS metrics (
    metric_id TEXT NOT NULL,
    dataset_id TEXT NOT NULL,
    expression TEXT NOT NULL,
    source_column TEXT,
    aggregation TEXT,
    grain TEXT,
    description TEXT,
    created_by TEXT DEFAULT 'agent',
    verified INTEGER DEFAULT 0,
    version INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    PRIMARY KEY (dataset_id, metric_id, version)
);
CREATE TABLE IF NOT EXISTS dimensions (
    dimension_id TEXT NOT NULL,
    dataset_id TEXT NOT NULL,
    expr TEXT NOT NULL,
    source_column TEXT,
    join_path TEXT,
    tables_json TEXT,
    joins_json TEXT,
    cardinality TEXT,
    type TEXT,
    description TEXT,
    entity INTEGER DEFAULT 0,
    created_by TEXT DEFAULT 'agent',
    verified INTEGER DEFAULT 0,
    version INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    PRIMARY KEY (dataset_id, dimension_id, version)
);
CREATE TABLE IF NOT EXISTS schema_state (
    dataset_id TEXT NOT NULL,
    table_name TEXT NOT NULL,
    column_name TEXT NOT NULL,
    column_type TEXT,
    checksum TEXT NOT NULL,
    last_seen_at TEXT NOT NULL,
    PRIMARY KEY (dataset_id, table_name, column_name)
);
CREATE TABLE IF NOT EXISTS registry_meta (
    dataset_id TEXT PRIMARY KEY,
    schema_checksum TEXT,
    seeded_from_profile INTEGER DEFAULT 0,
    updated_at TEXT NOT NULL
);
SQL);
    }

    public static function datasetId(): string
    {
        return self::$overrideDatasetId ?? DatasetProfile::id();
    }

    /** Session/orchestrator active dataset (multi-dataset dispatch). */
    public static function setActiveDataset(?string $datasetId): void
    {
        $id = $datasetId !== null ? strtolower(trim($datasetId)) : '';
        self::$overrideDatasetId = $id !== '' ? $id : null;
    }

    public static function resetConnection(): void
    {
        self::$pdo = null;
    }
}
