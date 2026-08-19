<?php

declare(strict_types=1);

namespace App\Tools;

/**
 * Contract for large-warehouse analyze_* tools:
 * - Aggregation always runs over the full filtered fact set (no sample LIMIT).
 * - Outer LIMIT only caps returned groups/buckets for UI/LLM safety.
 * - Fan-out filters use EXISTS; grain AVG/SUM with item dims use dedupe.
 * - Physical indexes are bootstrapped from the join/dimension catalog.
 */
final class FullDataContract
{
    /** Soft cap on returned aggregate groups (not scanned rows). */
    public static function groupCap(): int
    {
        $env = app_env('DWH_AGGREGATE_GROUP_CAP', '50000');
        $n = (int) ($env ?? 50000);
        return max(5000, min(500000, $n > 0 ? $n : 50000));
    }

    /**
     * @return array<string,mixed>
     */
    public static function meta(bool $fullScan = true, ?string $strategy = null): array
    {
        return [
            'full_data_scan' => $fullScan,
            'execution_mode' => 'aggregate',
            'group_cap' => self::groupCap(),
            'fan_out_strategy' => $strategy,
            'note' => 'Warehouse aggregation scanned the full filtered set; '
                . 'any LIMIT only caps returned groups for context size.',
        ];
    }

    /** Call once per process before analyze_*. */
    public static function prepareRuntime(): array
    {
        return IndexBootstrap::ensure();
    }
}
