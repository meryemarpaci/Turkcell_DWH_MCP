<?php

declare(strict_types=1);

namespace App\Tools;

use App\SemanticConfig;
use PDOException;

final class ProbeTool
{
    public function __construct(private readonly SqlGuard $guard)
    {
    }

    /**
     * @param list<string> $joinIds
     */
    public function probeJoin(array $joinIds, ?string $extraSql = null): array
    {
        if ($extraSql) {
            return $this->runProbe($extraSql, 'join_sql');
        }

        if ($joinIds === []) {
            return ['ok' => false, 'errors' => ['join_ids empty'], 'row_count' => 0, 'sample' => []];
        }

        $edges = [];
        foreach ($joinIds as $id) {
            $edge = SemanticConfig::joinById($id);
            if ($edge === null) {
                return ['ok' => false, 'errors' => ["Unknown join id: $id"], 'row_count' => 0, 'sample' => []];
            }
            $edges[] = $edge;
        }

        // Build a simple FROM + JOIN chain from first left table
        $base = $edges[0]['left_table'];
        $sql = 'SELECT COUNT(*) AS cnt FROM ' . $base;
        $joined = [$base => true];
        foreach ($edges as $e) {
            $lt = $e['left_table'];
            $rt = $e['right_table'];
            if (!isset($joined[$rt]) && isset($joined[$lt])) {
                $sql .= sprintf(
                    ' JOIN %s ON %s.%s = %s.%s',
                    $rt,
                    $lt,
                    $e['left_key'],
                    $rt,
                    $e['right_key']
                );
                $joined[$rt] = true;
            } elseif (!isset($joined[$lt]) && isset($joined[$rt])) {
                $sql .= sprintf(
                    ' JOIN %s ON %s.%s = %s.%s',
                    $lt,
                    $rt,
                    $e['right_key'],
                    $lt,
                    $e['left_key']
                );
                $joined[$lt] = true;
            } else {
                // try attach left then right
                if (!isset($joined[$lt])) {
                    return [
                        'ok' => false,
                        'errors' => ["Cannot attach join {$e['id']}: {$lt} not reachable yet"],
                        'suggestion' => 'Reorder join_ids so tables chain from a common root',
                        'row_count' => 0,
                        'sample' => [],
                    ];
                }
            }
        }

        $countResult = $this->runProbe($sql, 'join_count');
        if (!$countResult['ok']) {
            return $countResult;
        }

        $sampleSql = preg_replace('/^SELECT COUNT\(\*\) AS cnt/i', 'SELECT *', $sql) . ' LIMIT 5';
        $sample = $this->runProbe((string) $sampleSql, 'join_sample');

        $cnt = (int) (($countResult['sample'][0]['cnt'] ?? 0));
        $ok = $cnt > 0;
        return [
            'ok' => $ok,
            'errors' => $ok ? [] : ['Join returned 0 rows — check join keys / missing edges'],
            'warnings' => $sample['warnings'] ?? [],
            'row_count' => $cnt,
            'sample' => $sample['sample'] ?? [],
            'sql_used' => $sql,
            'join_ids' => $joinIds,
        ];
    }

    public function probeFilter(string $sql): array
    {
        $check = $this->guard->validate($sql);
        if (!$check['ok']) {
            return ['ok' => false, 'errors' => $check['errors'], 'row_count' => 0, 'sample' => []];
        }
        $statement = $check['statement'];

        // Prefer COUNT wrapper when possible
        $countSql = 'SELECT COUNT(*) AS cnt FROM (' . $statement . ') AS _f';
        $count = $this->runProbe($countSql, 'filter_count');
        if (!$count['ok']) {
            // fallback: limited execute
            return $this->runProbe($statement, 'filter_fallback');
        }
        $cnt = (int) (($count['sample'][0]['cnt'] ?? 0));
        $sampleSql = 'SELECT * FROM (' . $statement . ') AS _f LIMIT 5';
        $sample = $this->runProbe($sampleSql, 'filter_sample');

        $ok = $cnt > 0;
        return [
            'ok' => $ok,
            'errors' => $ok ? [] : ['Filter returned 0 rows — loosen filters or fix predicates'],
            'warnings' => array_merge($check['warnings'], $sample['warnings'] ?? []),
            'row_count' => $cnt,
            'sample' => $sample['sample'] ?? [],
            'sql_used' => $statement,
        ];
    }

    private function runProbe(string $sql, string $label): array
    {
        $check = $this->guard->validate($sql);
        if (!$check['ok']) {
            return [
                'ok' => false,
                'errors' => $check['errors'],
                'warnings' => $check['warnings'],
                'row_count' => 0,
                'sample' => [],
                'label' => $label,
            ];
        }
        try {
            $pdo = Database::pdo();
            $limit = $this->guard->clampMaxRows(SqlGuard::PEEK_MAX_ROWS, 'peek');
            $wrapped = $this->guard->wrapWithLimit($check['statement'], $limit);
            $rows = $pdo->query($wrapped)->fetchAll();
            return [
                'ok' => true,
                'errors' => [],
                'warnings' => $check['warnings'],
                'row_count' => count($rows),
                'sample' => array_slice($rows, 0, $limit),
                'label' => $label,
                'sql_used' => $check['statement'],
                'execution_mode' => 'peek',
            ];
        } catch (PDOException $e) {
            return [
                'ok' => false,
                'errors' => [$e->getMessage()],
                'row_count' => 0,
                'sample' => [],
                'label' => $label,
            ];
        }
    }
}
