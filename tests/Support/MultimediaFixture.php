<?php

namespace Tests\Support;

use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\VehicleBrand;
use App\Models\VehicleModel;
use App\Models\VehicleMultimediaSystem;
use App\Models\VehicleVersion;
use App\Services\ProductConfiguratorRegistry;

final class MultimediaFixture
{
    public static function create(string $profile = 'vehicle-oem-memory-v1', string $brandName = 'BMW'): array
    {
        $tag = $profile === 'vehicle-oem-memory-v1' ? 'android' : 'linux';
        $category = ProductCategory::create(['name' => 'Isolated '.$tag, 'slug' => $tag, 'is_active' => true, 'requires_oem_compatibility' => true]);
        $product = Product::create(['name' => 'Isolated multimedia '.$tag, 'slug' => 'isolated-'.$tag,
            'category_id' => $category->id, 'price' => 1, 'is_active' => true, 'is_visible' => true,
            'main_image' => 'products/isolated-fixture.png', 'compatibility_type' => 'vehicle_specific', 'requires_shipping' => true])->fresh();
        $brand = VehicleBrand::create(['name' => $brandName, 'slug' => $tag, 'is_active' => true]);
        $model = VehicleModel::create(['name' => 'Isolated model', 'slug' => $tag, 'vehicle_brand_id' => $brand->id, 'is_active' => true]);
        $version = VehicleVersion::create(['name' => 'Isolated generation', 'vehicle_model_id' => $model->id, 'year_from' => 2012, 'year_to' => 2018, 'is_active' => true]);
        $oems = [];
        foreach ($brandName === 'Toyota' ? ['Touch', 'Touch 2'] : ['CIC', 'NBT'] as $index => $name) {
            $oem = VehicleMultimediaSystem::create(['name' => $name, 'slug' => str_replace(' ', '-', strtolower($name)), 'vehicle_brand_id' => $brand->id, 'is_active' => true]);
            $version->multimediaSystems()->attach($oem->id, ['year_from' => $index === 0 ? 2012 : 2014, 'year_to' => $index === 0 ? 2013 : 2018]);
            $oems[] = $oem;
        }
        $variant = $product->variants()->create(['name' => 'Physical kit A', 'normalized_name' => 'physical kit a', 'sku' => 'ISOLATED-'.strtoupper($tag).'-A',
            'price' => 1200000, 'is_active' => true, 'is_visible' => true, 'is_default' => true, 'main_image' => 'products/isolated-variant.png']);
        foreach ($oems as $oem) {
            $variant->vehicleCompatibilities()->create(['vehicle_brand_id' => $brand->id, 'vehicle_model_id' => $model->id,
                'vehicle_version_id' => $version->id, 'vehicle_multimedia_system_id' => $oem->id]);
        }
        if ($profile === 'vehicle-oem-memory-v1') {
            foreach (['ram_gb' => 8, 'almacenamiento_gb' => 128] as $key => $value) {
                $field = $category->fields()->create(['name' => $key, 'field_key' => $key, 'type' => 'number', 'scope' => 'variant', 'filter_unit' => 'GB', 'is_active' => true]);
                $variant->specValues()->create(['product_category_field_id' => $field->id, 'value_number' => $value]);
            }
        }
        $branch = Branch::create(['name' => 'Isolated branch '.$tag, 'slug' => $tag, 'code' => strtoupper($tag), 'city' => 'Barranquilla', 'is_active' => true]);
        $item = InventoryItem::create(['product_variant_id' => $variant->id]);
        $stock = InventoryStock::create(['branch_id' => $branch->id, 'inventory_item_id' => $item->id, 'quantity' => 3]);
        config(['product_configurator.catalog' => app(ProductConfiguratorRegistry::class)->catalogIdentity($product),
            'product_configurator.products' => [$product->id => ['profile_id' => $profile, 'profile_version' => 1]],
            'product_configurator.preview_enabled' => true, 'product_configurator.purchase_enabled' => true]);
        $context = ['schema_version' => 1, 'profile_id' => $profile, 'profile_version' => 1,
            'vehicle_brand_id' => $brand->id, 'vehicle_model_id' => $model->id, 'vehicle_version_id' => $version->id,
            'vehicle_multimedia_system_id' => $oems[0]->id, 'year' => 2013];
        if ($profile === 'vehicle-oem-memory-v1') {
            $context += ['ram_gb' => 8, 'almacenamiento_gb' => 128];
        }
        $payload = ['customer_name' => 'Isolated QA', 'customer_email' => 'isolated@example.test', 'customer_phone' => '3000000000',
            'items' => [['product_id' => $product->id, 'product_variant_id' => $variant->id, 'quantity' => 1, 'configuration_context' => $context]]];

        return compact('product', 'variant', 'category', 'brand', 'model', 'version', 'oems', 'branch', 'item', 'stock', 'context', 'payload');
    }
}
