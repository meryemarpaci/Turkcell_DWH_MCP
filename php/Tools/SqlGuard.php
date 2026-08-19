<?php

declare(strict_types=1);

namespace App\Tools;

final class SqlGuard
{
    private const FORBIDDEN = '/\b(INSERT|UPDATE|DELETE|DROP|ALTER|CREATE|REPLACE|TRUNCATE|ATTACH|DETACH|PRAGMA|VACUUM|REINDEX|GRANT|REVOKE)\b/i';

    /** Peek / control samples (execute_query, probes). */
    public const PEEK_MAX_ROWS = 10;

    /** Raw browse / non-aggregate listings. */
    public const BROWSE_MAX_ROWS = 100;

    /**
     * Aggregate result-set cap (groups/buckets), NOT fact-table scan size.
     * SQLite still aggregates over the full filtered set; this only caps returned groups.
     */
    public const AGGREGATE_MAX_ROWS = 50000;

    /** @param list<string> $allowedTables */
    public function __construct(
        private readonly array $allowedTables,
        private readonly int $defaultMaxRows = 40,
        private readonly int $hardMaxRows = 100,
        private readonly int $aggregateHardMaxRows = self::AGGREGATE_MAX_ROWS,
    ) {
    }

    public function validate(string $sql): array
    {
        $errors = [];
        $warnings = [];
        $text = trim($sql);
        if ($text === '') {
            return ['ok' => false, 'errors' => ['SQL is empty'], 'warnings' => [], 'statement' => ''];
        }

        // LLM sometimes emits JSON-escaped quotes inside SQL: \"SP\"
        $text = str_replace(['\\"', "\\'"], ['"', "'"], $text);

        // Normalise LLM-generated double-quoted string literals to single quotes.
        // SQLite treats "foo" as an identifier, not a string — swap only when the
        // content is clearly a string value (not a column/table name reference).
        $text = self::normalizeQuotes($text);

        $parts = array_values(array_filter(array_map('trim', explode(';', $text)), static fn ($p) => $p !== ''));
        if (count($parts) > 1) {
            $errors[] = 'Only a single SQL statement is allowed';
        }
        $statement = $parts[0] ?? $text;

        if (!preg_match('/^(WITH|SELECT)\b/i', $statement)) {
            $errors[] = 'Only SELECT / WITH…SELECT queries are allowed';
        }
        if (preg_match(self::FORBIDDEN, $statement)) {
            $errors[] = 'Forbidden keyword detected (DDL/DML/PRAGMA/etc.)';
        }

        $pattern = '/\b(' . implode('|', array_map('preg_quote', $this->allowedTables)) . ')\b/i';
        preg_match_all($pattern, $statement, $m);
        $referenced = array_unique(array_map('strtolower', $m[1] ?? []));
        if ($referenced === []) {
            $warnings[] = 'No known DWH tables detected in SQL';
        }

        $isAgg = $this->looksLikeAggregate($statement);
        if (!$isAgg && !preg_match('/\bLIMIT\b/i', $statement)) {
            $warnings[] = 'Non-aggregate SQL has no LIMIT; executor will enforce a browse/peek row cap (sample, not full-table analysis)';
        }
        if ($isAgg) {
            $warnings[] = 'Aggregate SQL: engine scans full filtered set; outer LIMIT only caps returned groups/buckets';
        }

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
            'statement' => $statement,
            'is_aggregate' => $isAgg,
        ];
    }

    /**
     * True when SQL reduces rows via GROUP BY or aggregate functions.
     * These queries analyze the full filtered fact set; LIMIT only caps result groups.
     */
    public function looksLikeAggregate(string $sql): bool
    {
        if (preg_match('/\bGROUP\s+BY\b/i', $sql)) {
            return true;
        }
        // Aggregate in SELECT list (including DISTINCT forms)
        if (preg_match('/\b(COUNT|SUM|AVG|MIN|MAX|TOTAL)\s*\(/i', $sql)) {
            return true;
        }
        return false;
    }

    /**
     * Resolve execution mode from SQL shape + optional report_type.
     * report_type alone never upgrades a raw SELECT to full-data aggregate.
     *
     * @return 'peek'|'browse'|'aggregate'
     */
    public function resolveMode(string $sql, ?string $reportType = null, string $caller = 'report'): string
    {
        if ($caller === 'peek') {
            return 'peek';
        }
        $type = strtolower(trim((string) $reportType));
        if ($type === 'browse') {
            return 'browse';
        }
        if ($this->looksLikeAggregate($sql)) {
            return 'aggregate';
        }
        // Raw SELECT with kpi/trend/table → sample browse, not full-dataset analysis
        return 'browse';
    }

    public function clampMaxRows(?int $maxRows, string $mode = 'browse'): int
    {
        $aggCap = FullDataContract::groupCap();
        return match ($mode) {
            'peek' => max(1, min($maxRows ?? self::PEEK_MAX_ROWS, self::PEEK_MAX_ROWS)),
            'aggregate' => max(1, min($maxRows ?? $aggCap, $aggCap)),
            default => max(1, min($maxRows ?? self::BROWSE_MAX_ROWS, $this->hardMaxRows)),
        };
    }

    /** @deprecated Prefer clampMaxRows($n, $mode) */
    public function clampMaxRowsLegacy(?int $maxRows): int
    {
        $n = $maxRows ?? $this->defaultMaxRows;
        return max(1, min($n, $this->hardMaxRows));
    }

    public function wrapWithLimit(string $statement, int $maxRows): string
    {
        // Single-bucket aggregates (KPI): no group cap needed
        if ($maxRows >= FullDataContract::groupCap()
            && !preg_match('/\bGROUP\s+BY\b/i', $statement)
        ) {
            return $statement;
        }
        return 'SELECT * FROM (' . $statement . ') AS _q LIMIT ' . $maxRows;
    }

    /**
     * Remove trailing LIMIT/OFFSET the model often appends to analytical SQL.
     * Only strips the outermost trailing clause (not LIMIT keywords inside string literals).
     *
     * @return array{sql:string,removed:bool,removed_limit:?int}
     */
    public function stripTrailingLimit(string $sql): array
    {
        $sql = rtrim($sql);
        $removedLimit = null;

        // Work outside of quoted strings so we don't touch LIMIT inside literals.
        $parts = preg_split("/('(?:[^']|'')*')/", $sql, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false || $parts === []) {
            return ['sql' => $sql, 'removed' => false, 'removed_limit' => null];
        }

        // Find last non-string segment and strip trailing LIMIT there.
        for ($i = count($parts) - 1; $i >= 0; $i--) {
            $part = $parts[$i];
            if ($part === '' || str_starts_with($part, "'")) {
                continue;
            }
            if (preg_match('/^(.*?)(\s+LIMIT\s+(\d+)(\s+OFFSET\s+\d+)?)\s*$/is', $part, $m)) {
                $parts[$i] = rtrim($m[1]);
                $removedLimit = (int) $m[3];
                return [
                    'sql' => rtrim(implode('', $parts)),
                    'removed' => true,
                    'removed_limit' => $removedLimit,
                ];
            }
            break;
        }

        return ['sql' => $sql, 'removed' => false, 'removed_limit' => null];
    }

    /**
     * Prepare statement for execution: for aggregates, drop model-injected LIMIT
     * so full filtered data is analyzed; safety cap is applied only via wrapWithLimit.
     *
     * @return array{statement:string,warnings:list<string>,stripped_limit:?int}
     */
    public function prepareStatement(string $statement, string $mode): array
    {
        $warnings = [];
        $strippedLimit = null;
        if ($mode === 'aggregate') {
            $strip = $this->stripTrailingLimit($statement);
            if ($strip['removed']) {
                $statement = $strip['sql'];
                $strippedLimit = $strip['removed_limit'];
                $warnings[] = "Removed SQL LIMIT {$strippedLimit} from aggregate query "
                    . '(full-data analysis; safety cap applied by executor only).';
            }
        }
        return [
            'statement' => $statement,
            'warnings' => $warnings,
            'stripped_limit' => $strippedLimit,
        ];
    }

    /**
     * Convert double-quoted string literals to single-quoted ones so SQLite
     * doesn't treat them as identifiers.
     * Strategy: tokenise by single-quoted strings (leave them alone), then
     * within each non-string segment replace "..." that look like values
     * (after = < > IN LIKE BETWEEN , ( operators) with '...'.
     */
    private static function normalizeQuotes(string $sql): string
    {
        // Capturing group keeps single-quoted literals in the split result
        // (without it, preg_split would DELETE them → "near ,: syntax error").
        $parts = preg_split(
            "/('(?:[^']|'')*')/",
            $sql,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );
        if ($parts === false) {
            return $sql;
        }
        $out = '';
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            if (str_starts_with($part, "'")) {
                $out .= $part;
                continue;
            }
            // "value" after comparison / list operators → 'value'
            $out .= preg_replace_callback(
                '/(?<=[=<>!,(])\s*"([^"]*)"/',
                static fn(array $m): string => " '" . str_replace("'", "''", $m[1]) . "'",
                $part
            ) ?? $part;
        }
        return $out;
    }
}
