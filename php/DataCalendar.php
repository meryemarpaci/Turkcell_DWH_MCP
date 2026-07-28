<?php

declare(strict_types=1);

namespace App;

use App\Tools\Database;
use DateInterval;
use DateTimeImmutable;
use Throwable;

/**
 * Relative dates ("geçen ay", "bu ay") resolve against the last *substantial*
 * months in the DWH — Olist has a nearly-empty Sep/Oct 2018 tail that must
 * not be used as "last month" for sales questions.
 */
final class DataCalendar
{
    private const MIN_ORDERS_FOR_MONTH = 200;

    private static ?array $cache = null;

    public static function contextBlock(): string
    {
        $c = self::info();
        return implode("\n", [
            '# DATA CALENDAR (MUST use for relative dates — NOT wall-clock today)',
            "raw_min_purchase: {$c['min']}",
            "raw_max_purchase: {$c['max']}",
            "WARNING: {$c['tail_note']}",
            '',
            'Relative period mapping (use these exact ranges):',
            "- \"bu ay\" / current month → {$c['latest_month_start']} .. {$c['latest_month_end']}  ({$c['latest_month_orders']} orders dataset-wide)",
            "- \"geçen ay\" / last month → {$c['prev_month_start']} .. {$c['prev_month_end']}  ({$c['prev_month_orders']} orders dataset-wide)",
            "- \"bu yıl\" → {$c['latest_year']}-01-01 .. {$c['latest_month_end']}",
            '',
            'City/state shortcuts:',
            "- São Paulo / Sao Paulo / SP → dim_customer.customer_state = 'SP'",
            "- Rio / Rio de Janeiro / RJ → customer_state = 'RJ'",
            "- Default for satış/GMV: order_status = 'delivered' (unless user says otherwise)",
            '- Default metric: GMV = SUM(fact_order_items.price), also COUNT(DISTINCT fact_orders.order_id)',
            '- Prefer join: orders_customer + items_orders (never items+payments for GMV)',
            '',
            'If a filter returns 0 rows: widen status (drop delivered) or shift one month earlier — do not invent empty-business stories without retrying.',
        ]);
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
        if (self::$cache !== null) {
            return self::$cache;
        }

        $min = '2016-09-04';
        $max = '2018-10-17';
        $months = [];
        try {
            $pdo = Database::pdo();
            $min = (string) ($pdo->query('SELECT MIN(order_purchase_timestamp) FROM fact_orders')->fetchColumn() ?: $min);
            $max = (string) ($pdo->query('SELECT MAX(order_purchase_timestamp) FROM fact_orders')->fetchColumn() ?: $max);
            $sql = "SELECT substr(order_purchase_timestamp, 1, 7) AS m, COUNT(*) AS n
                    FROM fact_orders
                    GROUP BY 1
                    ORDER BY 1 DESC";
            $months = $pdo->query($sql)->fetchAll();
        } catch (Throwable) {
            // keep defaults
        }

        $substantial = [];
        foreach ($months as $row) {
            $n = (int) ($row['n'] ?? 0);
            if ($n >= self::MIN_ORDERS_FOR_MONTH) {
                $substantial[] = ['m' => (string) $row['m'], 'n' => $n];
            }
        }

        if ($substantial === []) {
            // fallback: two calendar months before max
            $maxDt = new DateTimeImmutable(substr($max, 0, 10));
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
            return self::$cache;
        }

        $latest = $substantial[0];
        $prev = $substantial[1] ?? $substantial[0];
        $latestYm = $latest['m']; // YYYY-MM
        $prevYm = $prev['m'];

        $tailSparse = [];
        foreach ($months as $row) {
            if ((int) $row['n'] < self::MIN_ORDERS_FOR_MONTH) {
                $tailSparse[] = $row['m'] . '(' . $row['n'] . ')';
            } else {
                break; // months are DESC; stop after first substantial
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
        return self::$cache;
    }

    private static function monthEnd(string $ym): string
    {
        $dt = new DateTimeImmutable($ym . '-01');
        return $dt->modify('last day of this month')->format('Y-m-d');
    }
}
