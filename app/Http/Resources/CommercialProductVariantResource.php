<?php

namespace App\Http\Resources;

use App\Models\ProductVariant;

class CommercialProductVariantResource
{
    /**
     * @param  array<int, int>  $stockByInventoryItem
     * @return array<string, mixed>
     */
    public static function make(ProductVariant $variant, array $stockByInventoryItem): array
    {
        $inventoryItemId = $variant->inventoryItem?->id;

        return [
            'id' => (int) $variant->id,
            'product_id' => (int) $variant->product_id,
            'name' => $variant->name,
            'display_name' => $variant->display_name,
            'sku' => $variant->sku,
            'price' => (int) $variant->price,
            'branch_stock' => $inventoryItemId ? (int) ($stockByInventoryItem[$inventoryItemId] ?? 0) : 0,
            'specs' => $variant->specs,
        ];
    }
}
