<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminProductVariantResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $variant = $this->resource;

        return [
            'id' => $variant->id,
            'product_id' => $variant->product_id,
            'vehicle_multimedia_system_id' => $variant->vehicle_multimedia_system_id,
            'compatibility_type' => $variant->compatibility_type,
            'effective_compatibility_type' => $variant->effective_compatibility_type,
            'name' => $variant->name,
            'normalized_name' => $variant->normalized_name,
            'display_name' => $variant->display_name,
            'sku' => $variant->sku,
            'attributes' => $variant->attributes,
            'specs' => $variant->specs,
            'spec_values' => $variant->relationLoaded('specValues')
                ? $variant->specValues->values()
                : [],
            'vehicle_compatibilities' => $variant->relationLoaded('vehicleCompatibilities')
                ? $variant->vehicleCompatibilities->values()
                : [],
            'cost_price' => $variant->cost_price,
            'tax_amount' => $variant->tax_amount,
            'extra_charges' => $variant->extra_charges,
            'total_cost' => $variant->total_cost,
            'price' => $variant->price,
            'profit_amount' => $variant->profit_amount,
            'profit_margin_percent' => $variant->profit_margin_percent,
            'markup_percent' => $variant->markup_percent,
            'pricing_mode' => $variant->pricing_mode,
            'target_profit_percent' => $variant->target_profit_percent,
            'weight_grams' => $variant->weight_grams,
            'length_mm' => $variant->length_mm,
            'width_mm' => $variant->width_mm,
            'height_mm' => $variant->height_mm,
            'country_of_origin' => $variant->country_of_origin,
            'hs_code' => $variant->hs_code,
            'customs_description' => $variant->customs_description,
            'effective_logistics' => $variant->resolvedLogistics(),
            'main_image' => $variant->main_image,
            'image_url' => $variant->image_url,
            'is_default' => $variant->is_default,
            'is_active' => $variant->is_active,
            'is_visible' => $variant->is_visible,
            'sort_order' => $variant->sort_order,
            'created_at' => $variant->created_at,
            'updated_at' => $variant->updated_at,
            'vehicle_multimedia_system' => $variant->relationLoaded('vehicleMultimediaSystem')
                ? $variant->vehicleMultimediaSystem
                : null,
        ];
    }
}
