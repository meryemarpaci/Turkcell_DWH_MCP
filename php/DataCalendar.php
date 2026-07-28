<?php

declare(strict_types=1);

namespace App;

use App\Tools\Database;
use DateInterval;
use DateTimeImmutable;
use Throwable;

/**
 * Relative dates resolve against substantial months in the active dataset profile.
 * Table/column/thresholds come from DatasetProfile::calendar() — not hardcoded Olist.
 */
final class DataCalendar
{
    private static ?array $cache = null;
    private static ?string $cacheKey = null;

    public static function contextBlock(): string
    {
        $c = self::info();
        $defaults = DatasetProfile::defaults();
        $lines = [
            '# DATA CALENDAR (MUST use for relative dates — NOT wall-clock today)',
            'profile: ' . DatasetProfile::id(),
            "raw_min: {$c['min']}",
            "raw_max: {$c['max']}",
            "WARNING: {$c['tail_note']}",
            '',
            'Relative period mapping (use these exact ranges):',
            "- \"bu ay\" / current month → {$c['latest_month_start']} .. {$c['latest_month_end']}  ({$c['latest_month_orders']} rows)",
            "- \"geçen ay\" / last month → {$c['prev_month_start']} .. {$c['prev_month_end']}  ({$c['prev_month_orders']} rows)",
            "- \"bu yıl\" → {$c['latest_year']}-01-01 .. {$c['latest_month_end']}",
            '',
            'City/state / domain shortcuts:',
        ];
        foreach (DatasetProfile::aliases() as $a) {
            $label = (string) ($a['label'] ?? 'alias');
            $sql = (string) ($a['sql'] ?? '');
            if ($sql !== '') {
                $lines[] = "- {$label} → {$sql}";
            }
        }
        $status = trim((string) ($defaults['status_filter_sql'] ?? ''));
        if ($status !== '') {
            $lines[] = "- Default status filter (unless user overrides): {$status}";
        }
        $metricNote = trim((string) ($defaults['primary_metric_note'] ?? ''));
        if ($metricNote !== '') {
            $lines[] = "- Default metric: {$metricNote}";
        }
        $joins = $defaults['preferred_join_ids'] ?? [];
        if (is_array($joins) && $joins !== []) {
            $lines[] = '- Prefer joins: ' . implode(', ', $joins);
        }
        $avoid = trim((string) ($defaults['avoid_join_note'] ?? ''));
        if ($avoid !== '') {
            $lines[] = "- {$avoid}";
        }
        $lines[] = '';
        $lines[] = 'If a filter returns 0 rows: widen filters or shift one month earlier — do not invent empty stories without retrying.';
        return implode("\n", $lines);
    }

    /**
     * @return array{
     *   min:string,max:string,
     *   latest_month_start:string,latest_month_end:string,latest_month_orders:int,
     *   prev_month_start:string,prev_month_end:string,prev_month_orders:int,
     *   latest_year:string,tail_note:string
     * }
     */
    public static function info(): array
    {
        $key = DatasetProfile::id();
        if (self::$cache !== null && self::$cacheKey === $key) {
            return self::$cache;
        }

        $cal = DatasetProfile::calendar();
        $fact = (string) ($cal['fact_table'] ?? '');
        $dateCol = (string) ($cal['date_column'] ?? '');
        $minRows = max(1, (int) ($cal['min_rows_per_month'] ?? 1));
        $min = (string) ($cal['fallback_min'] ?? '2000-01-01');
        $max = (string) ($cal['fallback_max'] ?? '2099-12-31');
        $months = [];

        if ($fact !== '' && $dateCol !== ''
            && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $fact)
            && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $dateCol)
            && in_array($fact, SemanticConfig::allowedTables(), true)
        ) {
            try {
                $pdo = Database::pdo();
                // Identifiers from profile only (allowlisted table)
                $minQ = $pdo->query("SELECT MIN({$dateCol}) FROM {$fact}");
                $maxQ = $pdo->query("SELECT MAX({$dateCol}) FROM {$fact}");
                $min = (string) ($minQ?->fetchColumn() ?: $min);
                $max = (string) ($maxQ?->fetchColumn() ?: $max);
                $sql = "SELECT substr({$dateCol}, 1, 7) AS m, COUNT(*) AS n
                        FROM {$fact}
                        GROUP BY 1
                        ORDER BY 1 DESC";
                $months = $pdo->query($sql)->fetchAll();
            } catch (Throwable) {
                // keep fallbacks
            }
        }

        $substantial = [];
        foreach ($months as $row) {
            $n = (int) ($row['n'] ?? 0);
            if ($n >= $minRows) {
                $substantial[] = ['m' => (string) $row['m'], 'n' => $n];
            }
        }

        if ($substantial === []) {
            $maxDt = new DateTimeImmutable(substr($max, 0, 10) ?: '2000-01-01');
            $latestStart = $maxDt->modify('first day of this month');
            $prevStart = $latestStart->sub(new DateInterval('P1M'));
            self::$cache = [
                'min' => substr($min, 0, 19),
                'max' => substr($max, 0, 19),
                'latest_month_start' => $latestStart->format('Y-m-d'),
                'latest_month_end' => $latestStart->modify('last day of this month')->format('Y-m-d'),
                'latest_month_orders' => 0,
                'prev_month_start' => $prevStart->format('Y-m-d'),
                'prev_month_end' => $prevStart->modify('last day of this month')->format('Y-m-d'),
                'prev_month_orders' => 0,
                'latest_year' => $latestStart->format('Y'),
                'tail_note' => 'No substantial months found; using calendar months of max date.',
            ];
            self::$cacheKey = $key;
            return self::$cache;
        }

        $latest = $substantial[0];
        $prev = $substantial[1] ?? $substantial[0];
        $latestYm = $latest['m'];
        $prevYm = $prev['m'];

        $tailSparse = [];
        foreach ($months as $row) {
            if ((int) $row['n'] < $minRows) {
                $tailSparse[] = $row['m'] . '(' . $row['n'] . ')';
            } else {
                break;
            }
        }

        self::$cache = [
            'min' => substr($min, 0, 19),
            'max' => substr($max, 0, 19),
            'latest_month_start' => $latestYm . '-01',
            'latest_month_end' => self::monthEnd($latestYm),
            'latest_month_orders' => $latest['n'],
            'prev_month_start' => $prevYm . '-01',
            'prev_month_end' => self::monthEnd($prevYm),
            'prev_month_orders' => $prev['n'],
            'latest_year' => substr($latestYm, 0, 4),
            'tail_note' => $tailSparse === []
                ? 'Dataset ends cleanly on last substantial month.'
                : ('Sparse tail months ignored for relative dates: ' . implode(', ', $tailSparse)),
        ];
        self::$cacheKey = $key;
        return self::$cache;
    }

    private static function monthEnd(string $ym): string
    {
        $dt = new DateTimeImmutable($ym . '-01');
        return $dt->modify('last day of this month')->format('Y-m-d');
    }
}
