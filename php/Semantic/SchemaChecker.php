<?php

declare(strict_types=1);

namespace App\Semantic;

use App\SemanticConfig;
use App\Tools\Database;
use PDO;

/**
 * Cheap physical schema checksum vs registry schema_state.
 * No LLM — orchestrator-only, millisecond-scale.
 */
final class SchemaChecker
{
    /**
     * @return array{
     *   ok:bool,
     *   dataset_id:string,
     *   checksum:string,
     *   changed:bool,
     *   new_columns:list<array{table:string,column:string,type:string}>,
     *   removed_columns:list<array{table:string,column:string}>,
     *   prompt_note:string
     * }
     */
    public function check(?string $datasetId = null): array
    {
        $ds = $datasetId ?? RegistryStore::datasetId();
        $pdoDwh = Database::pdo();
        $pdoReg = RegistryStore::pdo();

        $allowed = SemanticConfig::allowedTables();
        $physical = [];
        foreach ($allowed as $table) {
            $table = (string) $table;
            try {
                foreach ($pdoDwh->query("PRAGMA table_info({$table})") as $col) {
                    $name = (string) ($col['name'] ?? '');
                    if ($name === '') {
                        continue;
                    }
                    $type = (string) ($col['type'] ?? '');
                    $key = strtolower($table) . '.' . strtolower($name);
                    $physical[$key] = [
                        'table' => $table,
                        'column' => $name,
                        'type' => $type,
                        'checksum' => md5($table . '|' . $name . '|' . strtoupper($type)),
                    ];
                }
            } catch (\Throwable) {
                // table missing — skip
            }
        }

        $parts = array_keys($physical);
        sort($parts);
        $checksum = md5(implode(';', $parts));

        $st = $pdoReg->prepare('SELECT table_name, column_name, column_type, checksum FROM schema_state WHERE dataset_id = ?');
        $st->execute([$ds]);
        $known = [];
        foreach ($st->fetchAll() ?: [] as $row) {
            $key = strtolower((string) $row['table_name']) . '.' . strtolower((string) $row['column_name']);
            $known[$key] = $row;
        }

        $newCols = [];
        $removed = [];
        foreach ($physical as $key => $info) {
            if (!isset($known[$key])) {
                $newCols[] = [
                    'table' => $info['table'],
                    'column' => $info['column'],
                    'type' => $info['type'],
                ];
            }
        }
        foreach ($known as $key => $row) {
            if (!isset($physical[$key])) {
                $removed[] = [
                    'table' => (string) $row['table_name'],
                    'column' => (string) $row['column_name'],
                ];
            }
        }

        $hadBaseline = $known !== [];
        $changed = $hadBaseline && ($newCols !== [] || $removed !== []);
        $now = gmdate('c');

        // Upsert current physical snapshot
        $pdoReg->prepare('DELETE FROM schema_state WHERE dataset_id = ?')->execute([$ds]);
        $ins = $pdoReg->prepare(
            'INSERT INTO schema_state (dataset_id, table_name, column_name, column_type, checksum, last_seen_at)
             VALUES (?,?,?,?,?,?)'
        );
        foreach ($physical as $info) {
            $ins->execute([
                $ds,
                $info['table'],
                $info['column'],
                $info['type'],
                $info['checksum'],
                $now,
            ]);
        }
        $pdoReg->prepare(
            'INSERT INTO registry_meta (dataset_id, schema_checksum, seeded_from_profile, updated_at)
             VALUES (?, ?, COALESCE((SELECT seeded_from_profile FROM registry_meta WHERE dataset_id = ?), 0), ?)
             ON CONFLICT(dataset_id) DO UPDATE SET schema_checksum = excluded.schema_checksum, updated_at = excluded.updated_at'
        )->execute([$ds, $checksum, $ds, $now]);

        // First snapshot = silent baseline (avoid flooding prompt with every column).
        // Later diffs: only columns not yet in semantic registry.
        $reg = new RegistryService($ds);
        $reg->ensureSeededFromProfile();
        $unregistered = [];
        if ($hadBaseline) {
            foreach ($newCols as $c) {
                $guessId = strtolower($c['column']);
                $asMetric = $reg->resolveMetric($guessId);
                $asDim = $reg->resolveDimension($guessId);
                if ($asMetric === null && $asDim === null) {
                    $unregistered[] = $c;
                }
            }
        }

        $note = '';
        if ($unregistered !== []) {
            $bits = [];
            foreach (array_slice($unregistered, 0, 12) as $c) {
                $bits[] = "{$c['table']}.{$c['column']} ({$c['type']})";
            }
            $note = 'SCHEMA DIFF: Yeni / registry dışı alanlar: ' . implode(', ', $bits)
                . '. Henüz semantic registry’de yok. Gerekirse describe_column ile incele, '
                . 'register_metric / register_dimension ile bir kez kaydet, sonra analyze_* kullan.';
        } elseif ($changed && $removed !== []) {
            $note = 'SCHEMA DIFF: Bazı kolonlar DWH’den kaybolmuş olabilir ('
                . count($removed) . ' adet). Registry eski id’leri tutuyor olabilir.';
        }

        return [
            'ok' => true,
            'dataset_id' => $ds,
            'checksum' => $checksum,
            'changed' => $changed,
            'new_columns' => $newCols,
            'unregistered_columns' => $unregistered,
            'removed_columns' => $removed,
            'prompt_note' => $note,
        ];
    }
}
