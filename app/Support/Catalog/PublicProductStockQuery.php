<?php

namespace App\Support\Catalog;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Read-only public catalog stock expressions.
 *
 * Public stock is the aggregate across active branches. Products with at
 * least one public variant use only those variants; their parent inventory
 * item is deliberately excluded.
 */
class PublicProductStockQuery
{
    public static function addProductAggregates(Builder $query): void
    {
        $query
            ->addSelect('products.*')
            ->selectSub(self::productAggregateSubquery('quantity'), 'public_stock')
            ->selectSub(self::productAggregateSubquery('minimum_quantity'), 'public_minimum');
    }

    public static function addVariantAggregates(Builder $query): void
    {
        $query
            ->addSelect('product_variants.*')
            ->selectSub(self::variantAggregateSubquery('quantity'), 'public_stock')
            ->selectSub(self::variantAggregateSubquery('minimum_quantity'), 'public_minimum');
    }

    public static function applyInStockFilter(Builder $query): void
    {
        $subquery = self::productAggregateSubquery('quantity');

        $query->whereRaw(
            '('.$subquery->toSql().') > ?',
            [...$subquery->getBindings(), 0],
        );
    }

    public static function applyStockSort(Builder $query): void
    {
        $subquery = self::productAggregateSubquery('quantity');

        $query->orderByRaw(
            '('.$subquery->toSql().') desc',
            $subquery->getBindings(),
        );
    }

    public static function stockStatus(int $stock, int $minimum): string
    {
        if ($stock <= 0) {
            return 'out_of_stock';
        }

        return $stock <= $minimum ? 'low_stock' : 'available';
    }

    public static function isLowStock(int $stock, int $minimum): bool
    {
        return $stock > 0 && $stock <= $minimum;
    }

    private static function productAggregateSubquery(string $column): QueryBuilder
    {
        $variants = self::eligibleVariantAggregateSubquery($column);
        $product = self::simpleProductAggregateSubquery($column);

        return DB::query()->selectRaw(
            'COALESCE(('.$variants->toSql().'), ('.$product->toSql().'), 0)',
            [...$variants->getBindings(), ...$product->getBindings()],
        );
    }

    private static function eligibleVariantAggregateSubquery(string $column): QueryBuilder
    {
        return DB::table('product_variants as public_variants')
            ->leftJoin(
                'inventory_items',
                'inventory_items.product_variant_id',
                '=',
                'public_variants.id',
            )
            ->leftJoin(
                'inventory_stocks',
                'inventory_stocks.inventory_item_id',
                '=',
                'inventory_items.id',
            )
            ->leftJoin('branches', function ($join) {
                $join
                    ->on('branches.id', '=', 'inventory_stocks.branch_id')
                    ->where('branches.is_active', true);
            })
            ->whereColumn('public_variants.product_id', 'products.id')
            ->where('public_variants.is_active', true)
            ->where('public_variants.is_visible', true)
            ->selectRaw(
                'SUM(CASE WHEN branches.id IS NOT NULL'
                .' THEN inventory_stocks.'.self::aggregateColumn($column)
                .' ELSE 0 END)'
            );
    }

    private static function simpleProductAggregateSubquery(string $column): QueryBuilder
    {
        return DB::table('inventory_items')
            ->join(
                'inventory_stocks',
                'inventory_stocks.inventory_item_id',
                '=',
                'inventory_items.id',
            )
            ->join('branches', 'branches.id', '=', 'inventory_stocks.branch_id')
            ->whereColumn('inventory_items.product_id', 'products.id')
            ->where('branches.is_active', true)
            ->selectRaw('COALESCE(SUM(inventory_stocks.'.self::aggregateColumn($column).'), 0)');
    }

    private static function variantAggregateSubquery(string $column): QueryBuilder
    {
        return DB::table('inventory_items')
            ->join(
                'inventory_stocks',
                'inventory_stocks.inventory_item_id',
                '=',
                'inventory_items.id',
            )
            ->join('branches', 'branches.id', '=', 'inventory_stocks.branch_id')
            ->whereColumn('inventory_items.product_variant_id', 'product_variants.id')
            ->where('branches.is_active', true)
            ->selectRaw('COALESCE(SUM(inventory_stocks.'.self::aggregateColumn($column).'), 0)');
    }

    private static function aggregateColumn(string $column): string
    {
        return match ($column) {
            'quantity', 'minimum_quantity' => $column,
        };
    }
}
