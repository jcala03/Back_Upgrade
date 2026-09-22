<?php

namespace App\Services;

use App\Http\Resources\CommercialProductResource;
use App\Models\Branch;
use App\Models\InventoryStock;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class CommercialProductCatalogService
{
    /**
     * @param  array{search?: string|null, limit?: int|string|null}  $filters
     * @return array{branch: array{id: int, code: string|null, name: string}, products: array<int, array<string, mixed>>}
     */
    public function forBranch(Branch $branch, array $filters = []): array
    {
        $products = $this->products($filters);
        $stockByInventoryItem = $this->stockByInventoryItem($branch, $products);

        return [
            'branch' => [
                'id' => (int) $branch->id,
                'code' => $branch->code,
                'name' => $branch->name,
            ],
            'products' => $products
                ->map(fn (Product $product): array => CommercialProductResource::make($product, $stockByInventoryItem))
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array{search?: string|null, limit?: int|string|null}  $filters
     * @return Collection<int, Product>
     */
    private function products(array $filters): Collection
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $limit = (int) ($filters['limit'] ?? 50);
        $limit = max(1, min($limit, 100));

        return Product::query()
            ->select(['id', 'name', 'sku', 'price'])
            ->where('is_active', true)
            ->with([
                'inventoryItem:id,product_id,product_variant_id',
                'activeVariants' => function ($query) {
                    $query
                        ->select(['id', 'product_id', 'name', 'sku', 'price', 'sort_order'])
                        ->with([
                            'inventoryItem:id,product_id,product_variant_id',
                            'specValues:id,product_variant_id,product_category_field_id,value_text,value_number,value_boolean',
                            'specValues.field:id,field_key',
                        ]);
                },
            ])
            ->when($search !== '', function (Builder $query) use ($search) {
                $like = "%{$search}%";

                $query->where(function (Builder $query) use ($like) {
                    $query
                        ->where('name', 'like', $like)
                        ->orWhere('sku', 'like', $like)
                        ->orWhereHas('activeVariants', fn (Builder $variant) => $variant
                            ->where('name', 'like', $like)
                            ->orWhere('sku', 'like', $like));
                });
            })
            ->orderBy('name')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return array<int, int>
     */
    private function stockByInventoryItem(Branch $branch, Collection $products): array
    {
        $inventoryItemIds = $products
            ->flatMap(fn (Product $product): array => [
                $product->inventoryItem?->id,
                ...$product->activeVariants
                    ->map(fn ($variant) => $variant->inventoryItem?->id)
                    ->all(),
            ])
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($inventoryItemIds->isEmpty()) {
            return [];
        }

        return InventoryStock::query()
            ->where('branch_id', $branch->id)
            ->whereIn('inventory_item_id', $inventoryItemIds)
            ->pluck('quantity', 'inventory_item_id')
            ->mapWithKeys(fn ($quantity, $inventoryItemId): array => [
                (int) $inventoryItemId => (int) $quantity,
            ])
            ->all();
    }
}
