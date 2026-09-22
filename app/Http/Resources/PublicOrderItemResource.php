<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicOrderItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $item = $this->resource;

        return [
            'id' => $item->id,
            'item_type' => $item->item_type,
            'product_id' => $item->product_id,
            'product_variant_id' => $item->product_variant_id,
            'product_name' => $item->product_name,
            'product_slug' => $item->product_slug,
            'product_sku' => $item->product_sku,
            'variant_name' => $item->variant_name,
            'variant_sku' => $item->variant_sku,
            'variant_specs' => $item->variant_specs,
            'quantity' => $item->quantity,
            'unit_price' => $item->unit_price,
            'subtotal' => $item->subtotal,
            'discount_amount' => $item->discount_amount,
            'total' => $item->total,
        ];
    }
}
