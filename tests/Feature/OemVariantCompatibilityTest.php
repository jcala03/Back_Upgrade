<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\VehicleBrand;
use App\Models\VehicleModel;
use App\Models\VehicleMultimediaSystem;
use App\Models\VehicleVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OemVariantCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    private function catalog(): array
    {
        $brand = VehicleBrand::create(['name' => 'BMW', 'slug' => 'bmw', 'is_active' => true]);
        $model = VehicleModel::create(['vehicle_brand_id' => $brand->id, 'name' => 'Serie 3', 'slug' => 'serie3', 'is_active' => true]);
        $f30 = VehicleVersion::create(['vehicle_model_id' => $model->id, 'name' => 'F30', 'year_from' => 2012, 'year_to' => 2019, 'is_active' => true]);
        $g20 = VehicleVersion::create(['vehicle_model_id' => $model->id, 'name' => 'G20', 'year_from' => 2020, 'is_active' => true]);
        $nbt = VehicleMultimediaSystem::create(['vehicle_brand_id' => $brand->id, 'name' => 'NBT', 'slug' => 'nbt', 'is_active' => true]);
        $cic = VehicleMultimediaSystem::create(['vehicle_brand_id' => $brand->id, 'name' => 'CIC', 'slug' => 'cic', 'is_active' => true]);
        $f30->multimediaSystems()->attach([$nbt->id, $cic->id]);
        $g20->multimediaSystems()->attach($nbt->id);
        $category = ProductCategory::create(['name' => 'Categoría configurable', 'slug' => 'configurable', 'requires_oem_compatibility' => true, 'is_active' => true]);
        $product = Product::create(['name' => 'Pantalla', 'slug' => 'pantalla', 'category_id' => $category->id, 'compatibility_type' => 'vehicle_specific', 'price' => 1, 'is_active' => true, 'is_visible' => true]);
        $product->vehicleCompatibilities()->create(['vehicle_brand_id' => $brand->id, 'vehicle_model_id' => $model->id, 'vehicle_version_id' => $f30->id]);
        $branch = Branch::create(['name' => 'Local', 'code' => 'BAQ', 'slug' => 'baq', 'city' => 'Barranquilla', 'is_active' => true]);

        return compact('brand', 'model', 'f30', 'g20', 'nbt', 'cic', 'category', 'product', 'branch');
    }

    private function variant(array $c, string $name, int $price, int $stock, array $rows): ProductVariant
    {
        $variant = $c['product']->variants()->create(['name' => $name, 'normalized_name' => strtolower($name), 'sku' => $name, 'price' => $price, 'compatibility_type' => 'vehicle_specific', 'is_active' => true, 'is_visible' => true]);
        foreach ($rows as [$version, $system]) {
            $variant->vehicleCompatibilities()->create(['vehicle_brand_id' => $c['brand']->id, 'vehicle_model_id' => $c['model']->id, 'vehicle_version_id' => $version->id, 'vehicle_multimedia_system_id' => $system->id]);
        }
        $item = InventoryItem::create(['product_variant_id' => $variant->id]);
        InventoryStock::create(['branch_id' => $c['branch']->id, 'inventory_item_id' => $item->id, 'quantity' => $stock]);

        return $variant;
    }

    private function selection(array $c): array
    {
        return ['vehicle_brand_id' => $c['brand']->id, 'vehicle_model_id' => $c['model']->id, 'vehicle_version_id' => $c['f30']->id, 'vehicle_multimedia_system_id' => $c['nbt']->id];
    }

    public function test_exact_row_controls_variants_price_stock_and_detail(): void
    {
        $c = $this->catalog();
        $good = $this->variant($c, 'F30-NBT', 200000, 2, [[$c['f30'], $c['nbt']]]);
        $this->variant($c, 'F30-CIC', 10000, 100, [[$c['f30'], $c['cic']]]);
        $this->variant($c, 'CROSS-ROWS', 5000, 100, [[$c['f30'], $c['cic']], [$c['g20'], $c['nbt']]]);
        $query = http_build_query($this->selection($c));
        $response = $this->getJson('/api/products?'.$query)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonCount(1, 'data.0.variants')->assertJsonPath('data.0.variants.0.id', $good->id)
            ->assertJsonPath('data.0.price', 200000)->assertJsonPath('data.0.lowest_variant_price', 200000)
            ->assertJsonPath('data.0.stock', 2)->assertJsonPath('data.0.total_variant_stock', 2);
        $this->getJson('/api/products/pantalla?'.$query)->assertOk()->assertJsonCount(1, 'data.variants')->assertJsonPath('data.variants.0.id', $good->id);
        $this->getJson('/api/products?'.$query.'&max_price=50000')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/products?'.$query.'&sort=price_asc')->assertOk()->assertJsonPath('data.0.lowest_variant_price', 200000);
        $this->getJson('/api/products?'.$query.'&sort=stock')->assertOk()->assertJsonPath('data.0.stock', 2);
        $this->assertTrue($response->json('data.0.product_category.requires_oem_compatibility'));
    }

    public function test_general_and_legacy_fields_cannot_invent_oem_compatibility(): void
    {
        $c = $this->catalog();
        $wrong = $this->variant($c, 'CIC-ONLY', 10000, 20, [[$c['f30'], $c['cic']]]);
        $wrong->update(['vehicle_multimedia_system_id' => $c['nbt']->id]);
        $this->variant($c, 'CROSS-ONLY', 5000, 50, [[$c['f30'], $c['cic']], [$c['g20'], $c['nbt']]]);
        $this->getJson('/api/products?'.http_build_query($this->selection($c)))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/products/pantalla?'.http_build_query($this->selection($c)))->assertNotFound();
        $selection = $this->selection($c);
        unset($selection['vehicle_multimedia_system_id']);
        $this->getJson('/api/products?'.http_build_query($selection))->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame($c['nbt']->id, $wrong->fresh()->vehicle_multimedia_system_id);
    }

    public function test_in_stock_ignores_incompatible_variants_and_inactive_systems(): void
    {
        $c = $this->catalog();
        $this->variant($c, 'NBT-EMPTY', 200000, 0, [[$c['f30'], $c['nbt']]]);
        $this->variant($c, 'CIC-STOCK', 10000, 100, [[$c['f30'], $c['cic']]]);
        $query = http_build_query($this->selection($c));
        $this->getJson('/api/products?'.$query.'&in_stock=1')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/products?'.$query.'&in_stock=0')->assertOk()->assertJsonPath('data.0.stock', 0);
        $c['nbt']->update(['is_active' => false]);
        $this->getJson('/api/products?'.$query)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_category_enforces_complete_variant_oem_rows_and_crud_is_reused(): void
    {
        $c = $this->catalog();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->postJson('/api/admin/product-categories', ['name' => 'Nueva categoría OEM', 'requires_oem_compatibility' => true])->assertCreated()->assertJsonPath('data.requires_oem_compatibility', true);
        foreach (['NBT EVO', 'MGU'] as $name) {
            $system = $this->postJson('/api/admin/vehicle-multimedia-systems', ['vehicle_brand_id' => $c['brand']->id, 'name' => $name, 'is_active' => true])->assertCreated()->json('data.id');
            $this->putJson('/api/admin/vehicle-versions/'.$c['f30']->id.'/multimedia-systems', ['multimedia_systems' => [
                ['vehicle_multimedia_system_id' => $system],
                ['vehicle_multimedia_system_id' => $c['nbt']->id],
                ['vehicle_multimedia_system_id' => $c['cic']->id],
            ]])->assertOk()->assertJsonCount(3, 'data.multimedia_systems');
        }
        $row = $this->selection($c);
        $payload = ['name' => 'Configurada', 'category_id' => $c['category']->id, 'is_visible' => false, 'compatibility_type' => 'universal', 'variants' => [
            ['name' => 'OEM', 'price' => 100000, 'compatibility_type' => 'vehicle_specific', 'vehicle_compatibilities' => [$row]],
        ]];
        foreach (array_keys($row) as $requiredKey) {
            $incomplete = $payload;
            unset($incomplete['variants'][0]['vehicle_compatibilities'][0][$requiredKey]);
            $this->postJson('/api/admin/products', $incomplete)->assertUnprocessable()->assertJsonValidationErrors('variants.0.vehicle_compatibilities.0.'.$requiredKey);
        }
        $saved = $this->postJson('/api/admin/products', $payload)->assertCreated()->json('data.id');
        $this->postJson('/api/admin/products/'.$saved, ['name' => 'Renombrada'])->assertOk();
        $this->getJson('/api/vehicle-versions?vehicle_model_id='.$c['model']->id)->assertOk();
        $this->getJson('/api/vehicle-multimedia-systems?vehicle_version_id='.$c['g20']->id)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $c['nbt']->id);
    }

    public function test_price_and_stock_sort_use_only_matching_variants(): void
    {
        $c = $this->catalog();
        $first = $c['product'];
        $this->variant($c, 'MATCH-200', 200000, 1, [[$c['f30'], $c['nbt']]]);
        $this->variant($c, 'OTHER-1', 1, 100, [[$c['f30'], $c['cic']]]);
        $c['product'] = Product::create(['name' => 'Segunda pantalla', 'slug' => 'segunda', 'price' => 1, 'category_id' => $c['category']->id, 'is_active' => true, 'is_visible' => true]);
        $this->variant($c, 'MATCH-150', 150000, 3, [[$c['f30'], $c['nbt']]]);
        $query = http_build_query($this->selection($c));
        $this->getJson('/api/products?'.$query.'&sort=price_asc')->assertOk()->assertJsonPath('data.0.id', $c['product']->id);
        $this->getJson('/api/products?'.$query.'&sort=stock')->assertOk()->assertJsonPath('data.0.id', $c['product']->id);
        $this->getJson('/api/products?'.$query.'&min_price=180000')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $first->id);
    }
}
