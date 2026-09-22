<?php

namespace App\Http\Resources;

use App\Models\Product;

class CommercialProductResource
{
    /**
     * @param  array<int, int>  $stockByInventoryItem
     * @return array<string, mixed>
     */
    public static function make(Product $product, array $stockByInventoryItem): array
    {
        $variants = $product->relationLoaded('activeVariants')
            ? $product->activeVariants
            : collect();

        $variantPayloads = $variants
            ->map(fn ($variant): array => CommercialProductVariantResource::make($variant, $stockByInventoryItem))
            ->values();

        $inventoryItemId = $product->inventoryItem?->id;
        $branchStock = $variantPayloads->isNotEmpty()
            ? (int) $variantPayloads->sum('branch_stock')
            : ($inventoryItemId ? (int) ($stockByInventoryItem[$inventoryItemId] ?? 0) : 0);

        return [
            'id' => (int) $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'price' => (int) $product->price,
            'has_variants' => $variantPayloads->isNotEmpty(),
            'branch_stock' => $branchStock,
            'variants' => $variantPayloads->all(),
        ];
    }
}
