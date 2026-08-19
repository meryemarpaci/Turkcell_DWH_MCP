<?php

declare(strict_types=1);

namespace App\Tools;

use App\DatasetProfile;
use App\SemanticConfig;

/**
 * Profile-driven (data-independent) analysis field catalog.
 * Rich profiles define dimensions explicitly; otherwise heuristics from schema/joins.
 */
final class DimensionCatalog
{
    /** @var array<string,array{expr:string,alias:string,tables:list<string>,joins:list<string>,entity?:bool}>|null */
    private static ?array $cache = null;

    private static ?string $cacheKey = null;

    /**
     * @return array<string,array{expr:string,alias:string,tables:list<string>,joins:list<string>,entity?:bool}>
     */
    public static function all(): array
    {
        $key = DatasetProfile::id();
        if (self::$cache !== null && self::$cacheKey === $key) {
            return self::$cache;
        }

        $profile = DatasetProfile::get();
        $fromProfile = self::fromProfile($profile['dimensions'] ?? []);
        if ($fromProfile !== []) {
            self::$cache = $fromProfile;
            self::$cacheKey = $key;
            return self::$cache;
        }

        self::$cache = self::fromHeuristics();
        self::$cacheKey = $key;
        return self::$cache;
    }

    /** @return list<string> */
    public static function ids(): array
    {
        return array_keys(self::all());
    }

    /** @return list<string> */
    public static function entityIds(): array
    {
        $out = [];
        foreach (self::all() as $id => $meta) {
            if (!empty($meta['entity']) || str_ends_with($id, '_id')) {
                $out[] = $id;
            }
        }
        return $out !== [] ? $out : self::ids();
    }

    public static function get(string $name): ?array
    {
        $all = self::all();
        $n = self::normalizeName($name);
        if ($n !== null && isset($all[$n])) {
            return $all[$n];
        }
        // Turkish / common aliases
        $mapped = match (strtolower(trim($name))) {
            'state', 'eyalet', 'il' => self::firstExisting(['customer_state', 'seller_state']),
            'city', 'sehir', 'şehir' => self::firstExisting(['customer_city', 'seller_city']),
            'category', 'kategori', 'category_name_english', 'category_name', 'product_category'
                => self::firstExisting(['category', 'product_category', 'category_name_english']),
            'magaza', 'mağaza', 'store', 'satici', 'satıcı', 'seller' => self::firstExisting(['seller_id', 'seller']),
            'urun', 'ürün', 'product' => self::firstExisting(['product_id', 'product']),
            'musteri', 'müşteri', 'customer' => self::firstExisting(['customer_id', 'customer']),
            default => null,
        };
        return $mapped !== null ? ($all[$mapped] ?? null) : null;
    }

    public static function reset(): void
    {
        self::$cache = null;
        self::$cacheKey = null;
    }

    /** @param mixed $raw @return array<string,array<string,mixed>> */
    private static function fromProfile(mixed $raw): array
    {
        if (!is_array($raw) || $raw === []) {
            return [];
        }
        $out = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = strtolower(trim((string) ($row['id'] ?? $row['alias'] ?? '')));
            $expr = trim((string) ($row['expr'] ?? ''));
            if ($id === '' || $expr === '') {
                continue;
            }
            $tables = array_values(array_map('strval', $row['tables'] ?? []));
            $joins = array_values(array_map('strval', $row['joins'] ?? []));
            if ($tables === []) {
                // Infer table from expr "table.column"
                if (preg_match('/^([a-zA-Z0-9_]+)\./', $expr, $m)) {
                    $tables = [$m[1]];
                }
            }
            $out[$id] = [
                'expr' => $expr,
                'alias' => $id,
                'tables' => $tables,
                'joins' => $joins,
                'entity' => !empty($row['entity']) || str_ends_with($id, '_id'),
            ];
        }
        return $out;
    }

    /** @return array<string,array<string,mixed>> */
    private static function fromHeuristics(): array
    {
        $out = [];
        $schema = (new SchemaTool())->listSchema(null);
        $tables = $schema['tables'] ?? [];
        $joins = SemanticConfig::joins();

        foreach ($tables as $tableName => $info) {
            $tableName = (string) $tableName;
            $cols = $info['columns'] ?? [];
            foreach ($cols as $col) {
                $colName = (string) ($col['name'] ?? '');
                if ($colName === '') {
                    continue;
                }
                $lower = strtolower($colName);
                $interesting = str_ends_with($lower, '_state')
                    || str_ends_with($lower, '_city')
                    || str_ends_with($lower, '_type')
                    || str_ends_with($lower, '_status')
                    || str_contains($lower, 'category')
                    || (str_ends_with($lower, '_id') && !in_array($lower, ['id'], true));
                if (!$interesting) {
                    continue;
                }
                $alias = $lower;
                // Prefer shorter alias without table prefix duplication
                if (isset($out[$alias])) {
                    continue;
                }
                $neededJoins = self::joinsTouchingTable($joins, $tableName);
                $out[$alias] = [
                    'expr' => $tableName . '.' . $colName,
                    'alias' => $alias,
                    'tables' => array_values(array_unique(array_merge([$tableName], self::tablesFromJoins($joins, $neededJoins)))),
                    'joins' => $neededJoins,
                    'entity' => str_ends_with($lower, '_id'),
                ];
            }
        }
        return $out;
    }

    /** @param list<array<string,mixed>> $joins @return list<string> */
    private static function joinsTouchingTable(array $joins, string $table): array
    {
        $ids = [];
        foreach ($joins as $j) {
            $left = (string) ($j['left_table'] ?? '');
            $right = (string) ($j['right_table'] ?? '');
            if ($left === $table || $right === $table) {
                $id = (string) ($j['id'] ?? '');
                if ($id !== '') {
                    $ids[] = $id;
                }
            }
        }
        return $ids;
    }

    /** @param list<array<string,mixed>> $joins @param list<string> $joinIds @return list<string> */
    private static function tablesFromJoins(array $joins, array $joinIds): array
    {
        $want = array_fill_keys($joinIds, true);
        $tables = [];
        foreach ($joins as $j) {
            $id = (string) ($j['id'] ?? '');
            if (!isset($want[$id])) {
                continue;
            }
            $tables[] = (string) ($j['left_table'] ?? '');
            $tables[] = (string) ($j['right_table'] ?? '');
        }
        return array_values(array_filter($tables));
    }

    private static function normalizeName(string $name): ?string
    {
        $n = strtolower(trim($name));
        $n = str_replace(
            ['dim_customer.', 'dim_product.', 'dim_seller.', 'fact_orders.', 'fact_order_items.', 'fact_order_payments.'],
            '',
            $n
        );
        return $n !== '' ? $n : null;
    }

    /** @param list<string> $candidates */
    private static function firstExisting(array $candidates): ?string
    {
        $all = self::all();
        foreach ($candidates as $c) {
            if (isset($all[$c])) {
                return $c;
            }
        }
        return null;
    }
}
