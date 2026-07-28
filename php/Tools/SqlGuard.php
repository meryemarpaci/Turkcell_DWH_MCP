<?php

declare(strict_types=1);

namespace App\Tools;

final class SqlGuard
{
    private const FORBIDDEN = '/\b(INSERT|UPDATE|DELETE|DROP|ALTER|CREATE|REPLACE|TRUNCATE|ATTACH|DETACH|PRAGMA|VACUUM|REINDEX|GRANT|REVOKE)\b/i';

    /** @param list<string> $allowedTables */
    public function __construct(
        private readonly array $allowedTables,
        private readonly int $defaultMaxRows = 40,
        private readonly int $hardMaxRows = 100,
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

        if (!preg_match('/\bLIMIT\b/i', $statement) && !preg_match('/\bGROUP\s+BY\b/i', $statement)) {
            $warnings[] = 'No LIMIT clause; executor will enforce a row cap';
        }

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
            'statement' => $statement,
        ];
    }

    public function clampMaxRows(?int $maxRows): int
    {
        $n = $maxRows ?? $this->defaultMaxRows;
        return max(1, min($n, $this->hardMaxRows));
    }

    public function wrapWithLimit(string $statement, int $maxRows): string
    {
        return 'SELECT * FROM (' . $statement . ') AS _q LIMIT ' . $maxRows;
    }
}
