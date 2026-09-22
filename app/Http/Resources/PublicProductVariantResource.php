<?php

namespace App\Http\Resources;

use App\Support\Catalog\PublicProductStockQuery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicProductVariantResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $variant = $this->resource;
        $stock = (int) $variant->public_stock;
        $minimum = (int) $variant->public_minimum;

        return [
            'id' => $variant->id,
            'product_id' => $variant->product_id,
            'vehicle_multimedia_system_id' => $variant->vehicle_multimedia_system_id,
            'compatibility_type' => $variant->compatibility_type,
            'name' => $variant->name,
            'sku' => $variant->sku,
            'attributes' => $variant->attributes,
            'price' => $variant->price,
            'stock' => $stock,
            'main_image' => $variant->main_image,
            'is_default' => $variant->is_default,
            'is_active' => $variant->is_active,
            'is_visible' => $variant->is_visible,
            'sort_order' => $variant->sort_order,
            'display_name' => $variant->display_name,
            'image_url' => $variant->image_url,
            'stock_status' => PublicProductStockQuery::stockStatus($stock, $minimum),
            'is_low_stock' => PublicProductStockQuery::isLowStock($stock, $minimum),
            'effective_compatibility_type' => $variant->effective_compatibility_type,
            'specs' => $variant->specs,
            'spec_values' => $variant->relationLoaded('specValues')
                ? $variant->specValues->map(fn ($value) => PublicProductResource::specValue($value))->values()
                : [],
            'vehicle_multimedia_system' => $variant->relationLoaded('vehicleMultimediaSystem')
                ? PublicProductResource::multimediaSystem($variant->vehicleMultimediaSystem)
                : null,
            'vehicle_compatibilities' => $variant->relationLoaded('vehicleCompatibilities')
                ? $variant->vehicleCompatibilities
                    ->map(fn ($compatibility) => PublicProductResource::variantCompatibility($compatibility))
                    ->values()
                : [],
        ];
    }
}
