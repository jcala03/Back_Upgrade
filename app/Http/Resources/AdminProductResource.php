<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminProductResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $product = $this->resource;

        return [
            'id' => $product->id,
            'name' => $product->name,
            'normalized_name' => $product->normalized_name,
            'slug' => $product->slug,
            'description' => $product->description,
            'category' => $product->category,
            'category_id' => $product->category_id,
            'product_category' => $product->relationLoaded('productCategory')
                ? $product->productCategory
                : null,
            'product_brand_id' => $product->product_brand_id,
            'product_brand' => $product->relationLoaded('productBrand')
                ? $product->productBrand
                : null,
            'compatibility_type' => $product->compatibility_type,
            'is_universal' => $product->is_universal,
            'sku' => $product->sku,
            'price' => $product->price,
            'cost_price' => $product->cost_price,
            'tax_amount' => $product->tax_amount,
            'extra_charges' => $product->extra_charges,
            'total_cost' => $product->total_cost,
            'profit_amount' => $product->profit_amount,
            'profit_margin_percent' => $product->profit_margin_percent,
            'markup_percent' => $product->markup_percent,
            'pricing_mode' => $product->pricing_mode,
            'target_profit_percent' => $product->target_profit_percent,
            'technical_specs' => $product->technical_specs,
            'requires_shipping' => $product->requires_shipping,
            'weight_grams' => $product->weight_grams,
            'length_mm' => $product->length_mm,
            'width_mm' => $product->width_mm,
            'height_mm' => $product->height_mm,
            'country_of_origin' => $product->country_of_origin,
            'hs_code' => $product->hs_code,
            'customs_description' => $product->customs_description,
            'spec_values' => $product->relationLoaded('specValues')
                ? $product->specValues->values()
                : [],
            'vehicle_compatibilities' => $product->relationLoaded('vehicleCompatibilities')
                ? $product->vehicleCompatibilities->values()
                : [],
            'variants' => $product->relationLoaded('variants')
                ? AdminProductVariantResource::collection($product->variants)->resolve($request)
                : [],
            'main_image' => $product->main_image,
            'image_url' => $product->image_url,
            'is_visible' => $product->is_visible,
            'is_featured' => $product->is_featured,
            'is_active' => $product->is_active,
            'has_variants' => $product->has_variants,
            'lowest_variant_price' => $product->lowest_variant_price,
            'variants_count' => $product->variants_count
                ?? ($product->relationLoaded('variants') ? $product->variants->count() : 0),
            'commission_enabled' => (bool) $product->commission_enabled,
            'commission_amount' => $product->commissionAmount(),
            'created_at' => $product->created_at,
            'updated_at' => $product->updated_at,
        ];
    }
}
