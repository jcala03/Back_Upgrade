<?php

namespace App\Http\Resources;

use App\Support\Catalog\PublicProductStockQuery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicProductResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $product = $this->resource;
        $stock = (int) $product->public_stock;
        $minimum = (int) $product->public_minimum;
        $hasPublicVariants = $product->relationLoaded('variants')
            && $product->variants->isNotEmpty();

        return [
            'id' => $product->id,
            'name' => $product->name,
            'slug' => $product->slug,
            'description' => $product->description,
            'category' => $product->category,
            'category_id' => $product->category_id,
            'product_brand_id' => $product->product_brand_id,
            'compatibility_type' => $product->compatibility_type,
            'sku' => $product->sku,
            'price' => $product->price,
            'stock' => $stock,
            'technical_specs' => $product->technical_specs,
            'main_image' => $product->main_image,
            'is_visible' => $product->is_visible,
            'is_featured' => $product->is_featured,
            'is_active' => $product->is_active,
            'image_url' => $product->image_url,
            'stock_status' => PublicProductStockQuery::stockStatus($stock, $minimum),
            'is_low_stock' => PublicProductStockQuery::isLowStock($stock, $minimum),
            'has_variants' => $product->has_variants,
            'lowest_variant_price' => $product->lowest_variant_price,
            'total_variant_stock' => $hasPublicVariants ? $stock : 0,
            'is_universal' => $product->is_universal,
            'product_category' => $product->relationLoaded('productCategory')
                ? self::category($product->productCategory)
                : null,
            'product_brand' => $product->relationLoaded('productBrand')
                ? self::brand($product->productBrand)
                : null,
            'spec_values' => $product->relationLoaded('specValues')
                ? $product->specValues->map(fn ($value) => self::specValue($value))->values()
                : [],
            'vehicle_compatibilities' => $product->relationLoaded('vehicleCompatibilities')
                ? $product->vehicleCompatibilities
                    ->map(fn ($compatibility) => self::productCompatibility($compatibility))
                    ->values()
                : [],
            'variants' => $product->relationLoaded('variants')
                ? PublicProductVariantResource::collection($product->variants)->resolve($request)
                : [],
        ];
    }

    public static function specValue(mixed $value): array
    {
        return [
            'id' => $value->id,
            'product_category_field_id' => $value->product_category_field_id,
            'value_text' => $value->value_text,
            'value_number' => $value->value_number,
            'value_boolean' => $value->value_boolean,
            'field' => $value->relationLoaded('field') ? self::field($value->field) : null,
        ];
    }

    public static function variantCompatibility(mixed $compatibility): array
    {
        return [
            'id' => $compatibility->id,
            'vehicle_brand_id' => $compatibility->vehicle_brand_id,
            'vehicle_model_id' => $compatibility->vehicle_model_id,
            'vehicle_version_id' => $compatibility->vehicle_version_id,
            'vehicle_multimedia_system_id' => $compatibility->vehicle_multimedia_system_id,
            'year_from' => $compatibility->year_from,
            'year_to' => $compatibility->year_to,
            'vehicle_brand' => $compatibility->relationLoaded('vehicleBrand') ? self::vehicleBrand($compatibility->vehicleBrand) : null,
            'vehicle_model' => $compatibility->relationLoaded('vehicleModel') ? self::vehicleModel($compatibility->vehicleModel) : null,
            'vehicle_version' => $compatibility->relationLoaded('vehicleVersion') ? self::vehicleVersion($compatibility->vehicleVersion) : null,
            'vehicle_multimedia_system' => $compatibility->relationLoaded('vehicleMultimediaSystem') ? self::multimediaSystem($compatibility->vehicleMultimediaSystem) : null,
        ];
    }

    public static function multimediaSystem(mixed $system): ?array
    {
        if (! $system) {
            return null;
        }

        return [
            'id' => $system->id,
            'vehicle_brand_id' => $system->vehicle_brand_id,
            'name' => $system->name,
            'slug' => $system->slug,
            'code' => $system->code,
            'description' => $system->description,
            'is_active' => $system->is_active,
            'vehicle_brand' => $system->relationLoaded('vehicleBrand') ? self::vehicleBrand($system->vehicleBrand) : null,
        ];
    }

    private static function category(mixed $category): ?array
    {
        if (! $category) {
            return null;
        }

        return [
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
            'description' => $category->description,
            'is_active' => $category->is_active,
            'fields' => $category->relationLoaded('fields')
                ? $category->fields->map(fn ($field) => self::field($field))->values()
                : [],
        ];
    }

    private static function field(mixed $field): ?array
    {
        if (! $field) {
            return null;
        }

        return [
            'id' => $field->id,
            'name' => $field->name,
            'field_key' => $field->field_key,
            'type' => $field->type,
            'scope' => $field->scope,
            'options' => $field->options,
            'is_required' => $field->is_required,
            'is_active' => $field->is_active,
            'is_filterable' => $field->is_filterable,
            'filter_label' => $field->filter_label,
            'filter_unit' => $field->filter_unit,
            'sort_order' => $field->sort_order,
        ];
    }

    private static function brand(mixed $brand): ?array
    {
        return $brand ? [
            'id' => $brand->id,
            'name' => $brand->name,
            'slug' => $brand->slug,
            'description' => $brand->description,
            'is_active' => $brand->is_active,
        ] : null;
    }

    private static function productCompatibility(mixed $compatibility): array
    {
        return [
            'id' => $compatibility->id,
            'vehicle_brand_id' => $compatibility->vehicle_brand_id,
            'vehicle_model_id' => $compatibility->vehicle_model_id,
            'vehicle_version_id' => $compatibility->vehicle_version_id,
            'vehicle_label' => $compatibility->vehicle_label,
            'vehicle_brand' => $compatibility->relationLoaded('vehicleBrand') ? self::vehicleBrand($compatibility->vehicleBrand) : null,
            'vehicle_model' => $compatibility->relationLoaded('vehicleModel') ? self::vehicleModel($compatibility->vehicleModel) : null,
            'vehicle_version' => $compatibility->relationLoaded('vehicleVersion') ? self::vehicleVersion($compatibility->vehicleVersion) : null,
        ];
    }

    private static function vehicleBrand(mixed $brand): ?array
    {
        return $brand ? [
            'id' => $brand->id,
            'name' => $brand->name,
            'slug' => $brand->slug,
            'description' => $brand->description,
            'is_active' => $brand->is_active,
        ] : null;
    }

    private static function vehicleModel(mixed $model): ?array
    {
        return $model ? [
            'id' => $model->id,
            'vehicle_brand_id' => $model->vehicle_brand_id,
            'name' => $model->name,
            'slug' => $model->slug,
            'description' => $model->description,
            'is_active' => $model->is_active,
        ] : null;
    }

    private static function vehicleVersion(mixed $version): ?array
    {
        return $version ? [
            'id' => $version->id,
            'vehicle_model_id' => $version->vehicle_model_id,
            'name' => $version->name,
            'year_from' => $version->year_from,
            'year_to' => $version->year_to,
            'description' => $version->description,
            'is_active' => $version->is_active,
            'display_name' => $version->display_name,
        ] : null;
    }
}
