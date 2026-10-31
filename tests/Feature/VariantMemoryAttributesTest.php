<?php

namespace Tests\Feature;

use App\Http\Resources\PublicProductVariantResource;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\VehicleBrand;
use App\Models\VehicleModel;
use App\Models\VehicleMultimediaSystem;
use App\Models\VehicleVersion;
use App\Services\ProductVariantSpecValueService;
use App\Services\VariantMemoryAttributes;
use App\Services\VariantTechnicalReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VariantMemoryAttributesTest extends TestCase
{
    use RefreshDatabase;

    private function category(): ProductCategory
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $category = ProductCategory::create(['name' => 'Pantallas Android', 'slug' => 'pantallas-android', 'is_active' => true]);
        foreach (['ram' => 'number', 'almacenamiento' => 'text'] as $key => $type) {
            $category->fields()->create(['name' => $key, 'field_key' => $key, 'type' => $type, 'scope' => 'product', 'is_active' => true]);
        }
        app(VariantMemoryAttributes::class)->prepare($category);

        return $category;
    }

    private function createVariant(ProductCategory $category, array $specs = []): array
    {
        $response = $this->postJson('/api/admin/products', [
            'name' => 'Producto de prueba', 'category_id' => $category->id, 'is_visible' => false,
            'technical_specs' => ['ram' => 4, 'almacenamiento' => 'Histórico'],
            'variants' => [[
                'name' => 'Nombre libre no interpretado', 'sku' => 'MEMORY-TEST',
                'price' => 1250000, 'cost_price' => 900000, 'compatibility_type' => 'universal', 'specs' => $specs,
            ]],
        ])->assertCreated();

        return [$response, $response->json('data.id'), $response->json('data.variants.0.id')];
    }

    public function test_preparation_is_additive_optional_and_idempotent(): void
    {
        $category = $this->category();
        $before = $category->fields()->orderBy('id')->get()->toArray();
        app(VariantMemoryAttributes::class)->prepare($category);
        $this->assertSame($before, $category->fields()->orderBy('id')->get()->toArray());
        $this->assertSame(4, $category->fields()->count());
        $this->assertSame(0, $category->fields()->where('scope', 'variant')->where('is_required', true)->count());
        $this->artisan('products:prepare-variant-memory-fields', ['--apply' => true])->assertSuccessful();
    }

    public function test_create_edit_recover_and_public_contract_do_not_expose_internal_costs(): void
    {
        $category = $this->category();
        [$response, $productId, $variantId] = $this->createVariant($category, ['ram_gb' => 4, 'almacenamiento_gb' => 64]);
        $this->assertEquals(4, $response->json('data.variants.0.specs.ram_gb'));
        $response->assertJsonPath('data.technical_specs.almacenamiento', 'Histórico');
        $this->postJson('/api/admin/products/'.$productId, ['variants' => [[
            'id' => $variantId, 'name' => 'Nombre libre no interpretado', 'sku' => 'MEMORY-TEST',
            'price' => 1250000, 'cost_price' => 900000, 'specs' => ['ram_gb' => 8],
        ]]])->assertOk();
        $variant = ProductVariant::with('specValues.field')->findOrFail($variantId);
        $this->assertEquals(['ram_gb' => 8, 'almacenamiento_gb' => 64], $variant->specs);
        $this->assertSame(1250000, $variant->price);
        $this->assertSame(900000, $variant->cost_price);
        $this->assertSame('MEMORY-TEST', $variant->sku);
        $this->getJson('/api/admin/products')->assertOk()->assertJsonPath('data.0.variants.0.sku', 'MEMORY-TEST');
        // Public resource contract can be inspected without publishing a fixture.
        $resource = (new PublicProductVariantResource($variant))->resolve(request());
        $this->assertEquals(8, $resource['specs']['ram_gb']);
        foreach (['cost_price', 'profit_amount', 'commission_amount'] as $private) {
            $this->assertArrayNotHasKey($private, $resource);
        }
    }

    public function test_empty_optional_specs_and_simple_products_remain_supported(): void
    {
        $category = $this->category();
        [, , $id] = $this->createVariant($category);
        $assessment = app(VariantTechnicalReadiness::class)->assess(ProductVariant::findOrFail($id));
        $this->assertFalse($assessment['structurally_ready']);
        $this->assertArrayHasKey('ram_gb', $assessment['issues']);
        $this->assertArrayHasKey('almacenamiento_gb', $assessment['issues']);
        $this->assertFalse($assessment['certifies_physical_compatibility']);
        $this->postJson('/api/admin/products', ['name' => 'Simple', 'is_visible' => false])->assertCreated();
    }

    public function test_invalid_memory_is_rejected_without_writing_product_records(): void
    {
        $category = $this->category();
        foreach ([0, -1, 1.5, '8 GB', true, 'Infinity', 10000000000] as $value) {
            $this->postJson('/api/admin/products', [
                'name' => 'Invalid', 'category_id' => $category->id, 'is_visible' => false,
                'variants' => [['name' => 'Invalid', 'specs' => ['ram_gb' => $value]]],
            ])->assertUnprocessable();
        }
        $this->assertDatabaseCount('products', 0);
    }

    public function test_partial_spec_sync_preserves_other_fields_and_explicit_clear_is_local(): void
    {
        $category = $this->category();
        $category->fields()->create(['name' => 'Kit', 'field_key' => 'kit', 'type' => 'text', 'scope' => 'variant', 'is_active' => true]);
        [, , $id] = $this->createVariant($category, ['ram_gb' => 4, 'almacenamiento_gb' => 64, 'kit' => 'A']);
        $variant = ProductVariant::findOrFail($id);
        $service = app(ProductVariantSpecValueService::class);
        $service->sync($variant, ['ram_gb' => 8]);
        $service->sync($variant, []);
        $this->assertEquals(['ram_gb' => 8, 'almacenamiento_gb' => 64, 'kit' => 'A'], $variant->load('specValues.field')->specs);
        $service->sync($variant, ['ram_gb' => null]);
        $this->assertEquals(['almacenamiento_gb' => 64, 'kit' => 'A'], $variant->load('specValues.field')->specs);
    }

    public function test_partial_category_updates_keep_definitions_and_saved_values(): void
    {
        $category = $this->category();
        $this->createVariant($category, ['ram_gb' => 4]);
        $field = $category->fields()->where('field_key', 'ram_gb')->firstOrFail();
        $this->postJson('/api/admin/product-categories/'.$category->id, ['name' => $category->name,
            'fields' => [['id' => $field->id, 'name' => 'RAM', 'type' => 'number']],
        ])->assertOk()->assertJsonCount(4, 'data.fields');
        $this->assertDatabaseHas('product_category_fields', ['id' => $field->id, 'scope' => 'variant', 'filter_unit' => 'GB']);
        $this->postJson('/api/admin/product-categories/'.$category->id, [
            'name' => 'Renombre que debe revertirse', 'replace_fields' => true, 'fields' => [],
        ])->assertUnprocessable();
        $this->assertSame('Pantallas Android', $category->fresh()->name);
        $this->assertDatabaseCount('product_category_fields', 4);
        $this->assertDatabaseCount('product_variant_spec_values', 1);
    }

    public function test_category_cannot_reinterpret_a_used_field_or_take_another_category_id(): void
    {
        $category = $this->category();
        $this->createVariant($category, ['ram_gb' => 4]);
        $field = $category->fields()->where('field_key', 'ram_gb')->firstOrFail();
        $this->postJson('/api/admin/product-categories/'.$category->id, ['name' => $category->name,
            'fields' => [['id' => $field->id, 'name' => 'RAM', 'type' => 'text', 'scope' => 'product']],
        ])->assertUnprocessable();
        $other = ProductCategory::create(['name' => 'Otra', 'slug' => 'otra']);
        $this->postJson('/api/admin/product-categories/'.$other->id, ['name' => $other->name,
            'fields' => [['id' => $field->id, 'name' => 'RAM', 'type' => 'number']],
        ])->assertUnprocessable();
    }

    public function test_other_categories_keep_their_numeric_rules(): void
    {
        $category = $this->category();
        $category->update(['slug' => 'otra']);
        $this->createVariant($category, ['ram_gb' => 0.5]);
    }

    public function test_readiness_validates_required_oem_without_certifying_it(): void
    {
        $category = $this->category();
        [, , $id] = $this->createVariant($category, ['ram_gb' => 8, 'almacenamiento_gb' => 128]);
        $variant = ProductVariant::findOrFail($id);
        $service = app(VariantTechnicalReadiness::class);
        $this->assertTrue($service->assess($variant)['structurally_ready']);
        $this->assertFalse($service->assess($variant, true)['structurally_ready']);
    }

    public function test_readiness_accepts_registered_cic_and_nbt_rows_for_the_same_physical_variant(): void
    {
        $category = $this->category();
        [, , $id] = $this->createVariant($category, ['ram_gb' => 8, 'almacenamiento_gb' => 256]);
        $brand = VehicleBrand::create(['name' => 'BMW', 'slug' => 'bmw', 'is_active' => true]);
        $model = VehicleModel::create(['vehicle_brand_id' => $brand->id, 'name' => 'Serie 3', 'slug' => 'serie-3', 'is_active' => true]);
        $version = VehicleVersion::create(['vehicle_model_id' => $model->id, 'name' => 'F30', 'year_from' => 2012, 'year_to' => 2018, 'is_active' => true]);
        $variant = ProductVariant::findOrFail($id);
        $variant->update(['compatibility_type' => 'vehicle_specific']);
        foreach (['CIC', 'NBT'] as $name) {
            $system = VehicleMultimediaSystem::create(['vehicle_brand_id' => $brand->id, 'name' => $name, 'slug' => strtolower($name), 'is_active' => true]);
            $version->multimediaSystems()->attach($system->id);
            $variant->vehicleCompatibilities()->create(['vehicle_brand_id' => $brand->id, 'vehicle_model_id' => $model->id, 'vehicle_version_id' => $version->id, 'vehicle_multimedia_system_id' => $system->id]);
        }
        $assessment = app(VariantTechnicalReadiness::class)->assess($variant, true);
        $this->assertTrue($assessment['structurally_ready']);
        $this->assertFalse($assessment['certifies_physical_compatibility']);
        $this->assertDatabaseCount('inventory_items', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_preparation_refuses_conflicting_definitions_without_overwriting_them(): void
    {
        $category = $this->category();
        $field = $category->fields()->where('field_key', 'ram_gb')->firstOrFail();
        $field->update(['scope' => 'product']);
        try {
            app(VariantMemoryAttributes::class)->prepare($category);
            $this->fail('Se esperaba una definición incompatible.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('ram_gb', $exception->errors());
        }
        $this->assertSame('product', $field->fresh()->scope);
        $this->assertDatabaseCount('product_category_fields', 4);
    }

    public function test_multiple_variants_round_trip_independently_through_admin_and_public_endpoints(): void
    {
        $this->assertSame('upgrade_test', DB::selectOne('SELECT DATABASE() AS name')->name);
        $category = $this->category();
        $definitions = $category->fields()->get()->toArray();
        $brand = VehicleBrand::create(['name' => 'BMW', 'slug' => 'bmw', 'is_active' => true]);
        $model = VehicleModel::create(['vehicle_brand_id' => $brand->id, 'name' => 'Serie 3', 'slug' => 'serie-3', 'is_active' => true]);
        $version = VehicleVersion::create(['vehicle_model_id' => $model->id, 'name' => 'F30', 'year_from' => 2012, 'year_to' => 2018, 'is_active' => true]);
        $variants = [];
        foreach (['CIC', 'NBT'] as $index => $name) {
            $system = VehicleMultimediaSystem::create(['vehicle_brand_id' => $brand->id, 'name' => $name, 'slug' => strtolower($name), 'is_active' => true]);
            $version->multimediaSystems()->attach($system->id);
            $variants[] = [
                'name' => 'Kit '.$name, 'sku' => 'TEST-'.$name, 'price' => 1000000 + $index * 200000,
                'cost_price' => 700000, 'tax_amount' => 10000, 'extra_charges' => 5000,
                'pricing_mode' => 'manual', 'is_active' => true, 'is_visible' => true,
                'is_default' => $index === 0, 'sort_order' => $index,
                'compatibility_type' => 'vehicle_specific',
                'vehicle_compatibilities' => [[
                    'vehicle_brand_id' => $brand->id, 'vehicle_model_id' => $model->id,
                    'vehicle_version_id' => $version->id, 'vehicle_multimedia_system_id' => $system->id,
                ]],
                'specs' => ['ram_gb' => $index === 0 ? 4 : 8, 'almacenamiento_gb' => $index === 0 ? 64 : 256],
            ];
        }
        $created = $this->postJson('/api/admin/products', [
            'name' => 'Fixture aislada multivariante', 'category_id' => $category->id, 'is_visible' => false,
            'compatibility_type' => 'universal', 'technical_specs' => ['ram' => 2, 'almacenamiento' => 'Histórico general'],
            'variants' => $variants,
        ])->assertCreated()->json('data');
        $ids = array_column($created['variants'], 'id');
        $before = ProductVariant::with('vehicleCompatibilities')->whereIn('id', $ids)->get()->keyBy('id');
        foreach ($variants as $index => &$variant) {
            $variant['id'] = $ids[$index];
            unset($variant['compatibility_type'], $variant['vehicle_compatibilities']);
        }
        unset($variant);
        // The outer list remains canonical; only the first variant's RAM changes.
        $variants[0]['specs'] = ['ram_gb' => 8];
        unset($variants[1]['specs']);
        $this->postJson('/api/admin/products/'.$created['id'], ['variants' => $variants])->assertOk();
        $admin = $this->getJson('/api/admin/products')->assertOk()->json('data.0');
        $this->assertEquals(['ram_gb' => 8, 'almacenamiento_gb' => 64], $admin['variants'][0]['specs']);
        $this->assertEquals(['ram_gb' => 8, 'almacenamiento_gb' => 256], $admin['variants'][1]['specs']);
        $this->assertEquals(['ram' => 2, 'almacenamiento' => 'Histórico general'], $admin['technical_specs']);
        foreach ($ids as $id) {
            $after = ProductVariant::with('vehicleCompatibilities')->findOrFail($id);
            $this->assertSame($before[$id]->getAttributes()['sku'], $after->getAttributes()['sku']);
            foreach (['price', 'cost_price', 'tax_amount', 'extra_charges', 'pricing_mode', 'is_active', 'is_visible', 'is_default'] as $key) {
                $this->assertSame($before[$id]->getAttributes()[$key], $after->getAttributes()[$key], $key);
            }
            $this->assertSame($before[$id]->vehicleCompatibilities->toArray(), $after->vehicleCompatibilities->toArray());
        }
        // Visibility exists only within this rollback-only fixture, never in the local commercial database.
        Product::findOrFail($created['id'])->update(['is_visible' => true]);
        foreach (['/api/products', '/api/products/'.$created['slug']] as $url) {
            $response = $this->getJson($url)->assertOk();
            $public = $response->json($url === '/api/products' ? 'data.0' : 'data');
            $this->assertEquals($admin['technical_specs'], $public['technical_specs']);
            foreach ($public['variants'] as $index => $variant) {
                $this->assertSame($ids[$index], $variant['id']);
                $this->assertEquals($admin['variants'][$index]['specs'], $variant['specs']);
                $this->assertCount(2, $variant['spec_values']);
                $this->assertSame('variant', $variant['spec_values'][0]['field']['scope']);
                $this->assertSame('GB', $variant['spec_values'][0]['field']['filter_unit']);
                $this->assertSame($admin['variants'][$index]['sku'], $variant['sku']);
                $this->assertSame($admin['variants'][$index]['price'], $variant['price']);
                $this->assertSame(0, $variant['stock']);
                $this->assertSame($admin['variants'][$index]['vehicle_compatibilities'][0]['vehicle_multimedia_system_id'], $variant['vehicle_compatibilities'][0]['vehicle_multimedia_system_id']);
                foreach (['cost_price', 'profit_amount', 'commission_amount', 'total_cost'] as $private) {
                    $this->assertArrayNotHasKey($private, $variant);
                }
            }
        }
        $this->assertSame($definitions, $category->fields()->get()->toArray());
        $this->assertDatabaseCount('inventory_items', 0);
        $this->assertDatabaseCount('inventory_stocks', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_clearing_one_variant_never_copies_general_memory_or_changes_another_variant(): void
    {
        $category = $this->category();
        [, $productId, $firstId] = $this->createVariant($category, ['ram_gb' => 4, 'almacenamiento_gb' => 64]);
        $second = ProductVariant::create([
            'product_id' => $productId, 'name' => '8/256 libre no interpretado', 'normalized_name' => 'segunda',
            'sku' => 'SECOND', 'price' => 2000000, 'compatibility_type' => 'universal',
        ]);
        $service = app(ProductVariantSpecValueService::class);
        $service->sync($second, ['ram_gb' => 8, 'almacenamiento_gb' => 256]);
        $first = ProductVariant::findOrFail($firstId);
        $service->sync($first, ['ram_gb' => '', 'almacenamiento_gb' => null]);
        $this->assertSame([], $first->fresh()->load('specValues.field')->specs);
        $this->assertEquals(['ram_gb' => 8, 'almacenamiento_gb' => 256], $second->fresh()->load('specValues.field')->specs);
        $service->sync($second, ['ram_gb' => null, 'almacenamiento_gb' => '']);
        $this->assertSame([], $second->fresh()->load('specValues.field')->specs);
        $this->getJson('/api/admin/products')->assertOk()
            ->assertJsonPath('data.0.technical_specs.almacenamiento', 'Histórico')
            ->assertJsonPath('data.0.variants.0.specs', [])
            ->assertJsonPath('data.0.variants.1.specs', []);
    }
}
