<?php

declare(strict_types=1);

namespace App\Tools;

use App\DatasetProfile;
use App\SemanticConfig;
use App\Semantic\RegistryService;
use PDO;
use Throwable;

/**
 * Ensures physical indexes for full-data analyze_* scans.
 * Generic: driven by join catalog + registry dimensions — not a specific dataset.
 * Uses a short writable connection (DWH read path stays query_only).
 */
final class IndexBootstrap
{
    private static bool $done = false;

    /** @var list<string> */
    private static array $created = [];

    public static function ensure(): array
    {
        if (self::$done) {
            return ['ok' => true, 'skipped' => true, 'created' => self::$created];
        }
        self::$done = true;

        $rel = DatasetProfile::sqliteRelativePath();
        $path = APP_ROOT . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel);
        if (!is_file($path)) {
            return ['ok' => false, 'errors' => ["sqlite missing: {$path}"]];
        }

        try {
            $pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (Throwable $e) {
            return ['ok' => false, 'errors' => [$e->getMessage()]];
        }

        $candidates = self::candidates();
        $created = [];
        foreach ($candidates as $c) {
            $table = $c['table'];
            $cols = $c['columns'];
            if ($table === '' || $cols === [] || !self::tableExists($pdo, $table)) {
                continue;
            }
            $safeCols = [];
            foreach ($cols as $col) {
                if (self::columnExists($pdo, $table, $col)) {
                    $safeCols[] = $col;
                }
            }
            if ($safeCols === []) {
                continue;
            }
            $name = 'idx_auto_' . preg_replace('/[^a-z0-9_]/i', '_', $table . '_' . implode('_', $safeCols));
            $name = substr($name, 0, 60);
            $colSql = implode(', ', $safeCols);
            try {
                $pdo->exec("CREATE INDEX IF NOT EXISTS {$name} ON {$table} ({$colSql})");
                $created[] = "{$table}({$colSql})";
            } catch (Throwable) {
                // ignore — may be read-only media or duplicate name clash
            }
        }

        // Helpful analytics PRAGMAs on the shared read connection
        try {
            $read = Database::pdo();
            $read->exec('PRAGMA busy_timeout = 60000');
            $read->exec('PRAGMA cache_size = -65536'); // ~64MB
            $read->exec('PRAGMA temp_store = MEMORY');
        } catch (Throwable) {
        }

        self::$created = $created;
        return ['ok' => true, 'created' => $created, 'candidate_count' => count($candidates)];
    }

    public static function reset(): void
    {
        self::$done = false;
        self::$created = [];
    }

    /**
     * @return list<array{table:string,columns:list<string>}>
     */
    private static function candidates(): array
    {
        $out = [];
        $add = static function (string $table, string ...$cols) use (&$out): void {
            $table = trim($table);
            $cols = array_values(array_filter(array_map('trim', $cols)));
            if ($table === '' || $cols === []) {
                return;
            }
            $key = strtolower($table . '|' . implode(',', $cols));
            $out[$key] = ['table' => $table, 'columns' => $cols];
        };

        foreach (SemanticConfig::joins() as $j) {
            $lt = (string) ($j['left_table'] ?? '');
            $rt = (string) ($j['right_table'] ?? '');
            $lk = (string) ($j['left_key'] ?? '');
            $rk = (string) ($j['right_key'] ?? '');
            if ($lt !== '' && $lk !== '') {
                $add($lt, $lk);
            }
            if ($rt !== '' && $rk !== '') {
                $add($rt, $rk);
            }
        }

        $cal = DatasetProfile::calendar();
        $fact = (string) ($cal['fact_table'] ?? '');
        $dateCol = (string) ($cal['date_column'] ?? '');
        if ($fact !== '' && $dateCol !== '') {
            $col = str_contains($dateCol, '.') ? (explode('.', $dateCol)[1] ?? $dateCol) : $dateCol;
            $add($fact, $col);
            // status + date composite when default status filter exists
            $statusSql = (string) (DatasetProfile::defaults()['status_filter_sql'] ?? '');
            if (preg_match('/([a-zA-Z0-9_]+)\.([a-zA-Z0-9_]+)\s*=/', $statusSql, $m)) {
                $add($m[1], $m[2]);
                $add($m[1], $m[2], $col);
            }
        }

        try {
            $reg = new RegistryService();
            $reg->ensureSeededFromProfile();
            $search = $reg->search('', 80);
            foreach ($search['dimensions'] as $d) {
                $id = (string) ($d['dimension_id'] ?? '');
                if ($id === '') {
                    continue;
                }
                $full = $reg->resolveDimension($id);
                if ($full === null) {
                    continue;
                }
                $expr = (string) ($full['expr'] ?? '');
                if (preg_match('/^([a-zA-Z0-9_]+)\.([a-zA-Z0-9_]+)$/', trim($expr), $m)) {
                    $add($m[1], $m[2]);
                }
            }
        } catch (Throwable) {
        }

        return array_values($out);
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
            return false;
        }
        $st = $pdo->prepare(
            "SELECT 1 FROM sqlite_master WHERE type='table' AND name = ? LIMIT 1"
        );
        $st->execute([$table]);
        return (bool) $st->fetchColumn();
    }

    private static function columnExists(PDO $pdo, string $table, string $column): bool
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table . $column)) {
            return false;
        }
        try {
            foreach ($pdo->query("PRAGMA table_info({$table})") as $c) {
                if (strcasecmp((string) ($c['name'] ?? ''), $column) === 0) {
                    return true;
                }
            }
        } catch (Throwable) {
            return false;
        }
        return false;
    }
}
