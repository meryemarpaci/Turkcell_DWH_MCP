<?php

declare(strict_types=1);

namespace App\Discovery;

/**
 * Safety rails for cross-domain joins.
 */
final class JoinSafetyGuard
{
    public const MAX_JOIN_DEPTH = 4;
    public const MIN_AUTO_CONFIDENCE = 0.55;
    public const ASK_USER_BELOW = 0.7;

    /**
     * @param array{
     *   tables?:list<string>,
     *   edges?:list<array<string,mixed>>,
     *   confidence?:float,
     *   fan_out_risk?:string
     * } $path
     * @return array{ok:bool,errors:list<string>,warnings:list<string>,needs_confirmation:bool,require_preaggregate:bool}
     */
    public function evaluate(array $path): array
    {
        $errors = [];
        $warnings = [];
        $tables = $path['tables'] ?? [];
        $edges = $path['edges'] ?? [];
        $conf = (float) ($path['confidence'] ?? 0);
        $fan = strtolower((string) ($path['fan_out_risk'] ?? 'medium'));

        $depth = max(0, count($tables) - 1);
        if ($depth > self::MAX_JOIN_DEPTH) {
            $errors[] = 'Join depth ' . $depth . ' exceeds max ' . self::MAX_JOIN_DEPTH;
        }
        if (count($tables) > self::MAX_JOIN_DEPTH + 1) {
            $errors[] = 'Too many tables in path (max ' . (self::MAX_JOIN_DEPTH + 1) . ')';
        }
        if ($conf < self::MIN_AUTO_CONFIDENCE && $edges !== []) {
            $errors[] = 'Join confidence ' . round($conf, 2) . ' below auto-run threshold '
                . self::MIN_AUTO_CONFIDENCE;
        }

        $needsConfirm = $conf > 0 && $conf < self::ASK_USER_BELOW;
        if ($needsConfirm) {
            $warnings[] = 'Low-confidence join path — confirm with user before analyzing.';
        }
        $requirePreAgg = in_array($fan, ['high', 'medium'], true) && count($edges) > 0;
        if ($fan === 'high') {
            $warnings[] = 'High fan-out risk: pre-aggregate the many-side before join to avoid double-counting.';
            $requirePreAgg = true;
        }

        foreach ($edges as $e) {
            if ((float) ($e['confidence_score'] ?? 0) < self::MIN_AUTO_CONFIDENCE) {
                $errors[] = 'Edge ' . ($e['edge_id'] ?? '?') . ' below confidence threshold';
            }
        }

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
            'needs_confirmation' => $needsConfirm,
            'require_preaggregate' => $requirePreAgg,
            'max_depth' => self::MAX_JOIN_DEPTH,
            'min_confidence' => self::MIN_AUTO_CONFIDENCE,
        ];
    }
}
