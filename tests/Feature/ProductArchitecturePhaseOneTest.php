<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\VehicleBrand;
use App\Models\VehicleModel;
use App\Models\VehicleMultimediaSystem;
use App\Models\VehicleVersion;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductArchitecturePhaseOneTest extends TestCase
{
    use RefreshDatabase;

    public function createApplication()
    {
        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        $connection = getenv('TEST_DB_CONNECTION');

        if ($connection) {
            $app['config']->set('database.default', $connection);
            $app['config']->set(
                "database.connections.{$connection}.database",
                getenv('TEST_DB_DATABASE') ?: 'upgrade_phase1_test'
            );
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
    }

    public function test_legacy_product_without_variants_still_creates(): void
    {
        $this->postJson('/api/admin/products', ['name' => 'Accesorio universal', 'is_visible' => false])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Accesorio universal');
    }

    public function test_legacy_product_with_compatibilities_still_creates(): void
    {
        [$brand] = $this->vehicleCatalog();

        $this->postJson('/api/admin/products', [
            'name' => 'Pieza legacy',
            'is_visible' => false,
            'compatibility_type' => 'vehicle_specific',
            'vehicle_compatibilities' => [['vehicle_brand_id' => $brand->id]],
        ])->assertCreated()->assertJsonCount(1, 'data.vehicle_compatibilities');
    }

    public function test_variant_accepts_normalized_variant_scoped_specs(): void
    {
        [$category, $field] = $this->categoryWithField('variant', 'operating_system', true, ['Android', 'Linux']);

        $response = $this->createProductWithVariant([
            'category_id' => $category->id,
            'variants' => [$this->variantPayload(['specs' => ['operating_system' => 'Android']])],
        ])->assertCreated();

        $response->assertJsonPath('data.variants.0.specs.operating_system', 'Android');
        $this->assertDatabaseHas('product_variant_spec_values', [
            'product_category_field_id' => $field->id,
            'value_text' => 'Android',
        ]);
    }

    public function test_product_scoped_field_is_rejected_inside_variant_specs(): void
    {
        [$category] = $this->categoryWithField('product', 'resolution');

        $this->createProductWithVariant([
            'category_id' => $category->id,
            'variants' => [$this->variantPayload(['specs' => ['resolution' => '4K']])],
        ])->assertUnprocessable()->assertJsonValidationErrors('specs.resolution');
    }

    public function test_universal_variant_does_not_require_compatibilities(): void
    {
        $this->createProductWithVariant([
            'variants' => [$this->variantPayload(['compatibility_type' => 'universal'])],
        ])->assertCreated()->assertJsonPath('data.variants.0.effective_compatibility_type', 'universal');
    }

    public function test_specific_variant_requires_compatibility(): void
    {
        $this->createProductWithVariant([
            'variants' => [$this->variantPayload(['compatibility_type' => 'vehicle_specific'])],
        ])->assertUnprocessable()->assertJsonValidationErrors('variants.0.vehicle_compatibilities');
    }

    public function test_body_kit_compatibility_does_not_require_oem_system(): void
    {
        [$brand, $model, $version] = $this->vehicleCatalog();

        $this->createProductWithVariant(['variants' => [$this->variantPayload([
            'compatibility_type' => 'vehicle_specific',
            'vehicle_compatibilities' => [[
                'vehicle_brand_id' => $brand->id,
                'vehicle_model_id' => $model->id,
                'vehicle_version_id' => $version->id,
                'year_from' => 2015,
                'year_to' => 2018,
            ]],
        ])]])->assertCreated()->assertJsonPath(
            'data.variants.0.vehicle_compatibilities.0.vehicle_multimedia_system_id',
            null
        );
    }

    public function test_screen_compatibility_accepts_matching_oem_system(): void
    {
        [$brand, $model, $version, $system] = $this->vehicleCatalog();

        $this->createProductWithVariant(['variants' => [$this->variantPayload([
            'compatibility_type' => 'vehicle_specific',
            'vehicle_compatibilities' => [$this->compatibilityPayload($brand, $model, $version, $system)],
        ])]])->assertCreated()->assertJsonPath(
            'data.variants.0.vehicle_compatibilities.0.vehicle_multimedia_system_id',
            $system->id
        );
    }

    public function test_model_must_belong_to_vehicle_brand(): void
    {
        [$brand] = $this->vehicleCatalog();
        [, $otherModel] = $this->vehicleCatalog('Other');

        $this->specificVariantRequest([[
            'vehicle_brand_id' => $brand->id,
            'vehicle_model_id' => $otherModel->id,
        ]])->assertUnprocessable()->assertJsonValidationErrors(
            'variants.0.vehicle_compatibilities.0.vehicle_model_id'
        );
    }

    public function test_version_must_belong_to_vehicle_model(): void
    {
        [$brand, $model] = $this->vehicleCatalog();
        [, , $otherVersion] = $this->vehicleCatalog('Other');

        $this->specificVariantRequest([[
            'vehicle_brand_id' => $brand->id,
            'vehicle_model_id' => $model->id,
            'vehicle_version_id' => $otherVersion->id,
        ]])->assertUnprocessable()->assertJsonValidationErrors(
            'variants.0.vehicle_compatibilities.0.vehicle_version_id'
        );
    }

    public function test_oem_system_must_belong_to_vehicle_brand(): void
    {
        [$brand, $model, $version] = $this->vehicleCatalog();
        [, , , $otherSystem] = $this->vehicleCatalog('Other');

        $this->specificVariantRequest([[
            'vehicle_brand_id' => $brand->id,
            'vehicle_model_id' => $model->id,
            'vehicle_version_id' => $version->id,
            'vehicle_multimedia_system_id' => $otherSystem->id,
        ]])->assertUnprocessable()->assertJsonValidationErrors(
            'variants.0.vehicle_compatibilities.0.vehicle_multimedia_system_id'
        );
    }

    public function test_invalid_year_range_is_rejected(): void
    {
        [$brand] = $this->vehicleCatalog();

        $this->specificVariantRequest([[
            'vehicle_brand_id' => $brand->id,
            'year_from' => 2020,
            'year_to' => 2019,
        ]])->assertUnprocessable()->assertJsonValidationErrors(
            'variants.0.vehicle_compatibilities.0.year_to'
        );
    }

    public function test_universal_product_appears_when_filtering_by_vehicle(): void
    {
        [$brand] = $this->vehicleCatalog();
        $product = $this->product(['compatibility_type' => 'universal']);

        $this->getJson("/api/products?vehicle_brand_id={$brand->id}")
            ->assertOk()->assertJsonPath('data.0.id', $product->id);
    }

    public function test_specific_matching_product_appears_in_vehicle_filter(): void
    {
        [$brand] = $this->vehicleCatalog();
        $product = $this->product(['compatibility_type' => 'vehicle_specific']);
        $product->vehicleCompatibilities()->create(['vehicle_brand_id' => $brand->id]);

        $this->getJson("/api/products?vehicle_brand_id={$brand->id}")
            ->assertOk()->assertJsonPath('data.0.id', $product->id);
    }

    public function test_specific_incompatible_product_does_not_appear(): void
    {
        [$brand] = $this->vehicleCatalog();
        [$otherBrand] = $this->vehicleCatalog('Other');
        $product = $this->product(['compatibility_type' => 'vehicle_specific']);
        $product->vehicleCompatibilities()->create(['vehicle_brand_id' => $otherBrand->id]);

        $this->getJson("/api/products?vehicle_brand_id={$brand->id}")
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_matching_active_variant_can_make_product_appear(): void
    {
        [$brand] = $this->vehicleCatalog();
        $product = $this->product(['compatibility_type' => 'vehicle_specific']);
        $variant = $this->variant($product, ['compatibility_type' => 'vehicle_specific']);
        $variant->vehicleCompatibilities()->create(['vehicle_brand_id' => $brand->id]);

        $this->getJson("/api/products?vehicle_brand_id={$brand->id}")
            ->assertOk()->assertJsonPath('data.0.id', $product->id);
    }

    public function test_inactive_variant_does_not_make_product_compatible(): void
    {
        [$brand] = $this->vehicleCatalog();
        $product = $this->product(['compatibility_type' => 'vehicle_specific']);
        $variant = $this->variant($product, [
            'compatibility_type' => 'vehicle_specific',
            'is_active' => false,
        ]);
        $variant->vehicleCompatibilities()->create(['vehicle_brand_id' => $brand->id]);

        $this->getJson("/api/products?vehicle_brand_id={$brand->id}")
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_legacy_category_filter_still_works(): void
    {
        $category = ProductCategory::create([
            'name' => 'Luces', 'slug' => 'luces', 'is_active' => true,
        ]);
        $product = $this->product(['category_id' => $category->id]);
        $this->product();

        $this->getJson("/api/products?category_id={$category->id}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $product->id);
    }

    public function test_updating_variant_replaces_normalized_specs(): void
    {
        [$category] = $this->categoryWithField('variant', 'material', true, ['ABS', 'Carbon']);
        $created = $this->createProductWithVariant([
            'category_id' => $category->id,
            'variants' => [$this->variantPayload(['specs' => ['material' => 'ABS']])],
        ])->assertCreated()->json('data');

        $this->postJson("/api/admin/products/{$created['id']}", [
            'variants' => [$this->variantPayload([
                'id' => $created['variants'][0]['id'],
                'specs' => ['material' => 'Carbon'],
            ])],
        ])->assertOk()->assertJsonPath('data.variants.0.specs.material', 'Carbon');

        $this->assertDatabaseMissing('product_variant_spec_values', ['value_text' => 'ABS']);
    }

    public function test_update_deduplicates_and_synchronizes_variant_compatibilities(): void
    {
        [$brand] = $this->vehicleCatalog();
        $created = $this->createProductWithVariant([
            'variants' => [$this->variantPayload(['compatibility_type' => 'universal'])],
        ])->assertCreated()->json('data');
        $compatibility = ['vehicle_brand_id' => $brand->id];

        $this->postJson("/api/admin/products/{$created['id']}", [
            'variants' => [$this->variantPayload([
                'id' => $created['variants'][0]['id'],
                'compatibility_type' => 'vehicle_specific',
                'vehicle_compatibilities' => [$compatibility, $compatibility],
            ])],
        ])->assertOk()->assertJsonCount(1, 'data.variants.0.vehicle_compatibilities');
    }

    private function createProductWithVariant(array $overrides = [])
    {
        return $this->postJson('/api/admin/products', array_replace([
            'name' => 'Producto '.fake()->unique()->word(),
            'variants' => [$this->variantPayload()],
            'is_visible' => false,
        ], $overrides));
    }

    private function specificVariantRequest(array $compatibilities)
    {
        return $this->createProductWithVariant(['variants' => [$this->variantPayload([
            'compatibility_type' => 'vehicle_specific',
            'vehicle_compatibilities' => $compatibilities,
        ])]]);
    }

    private function variantPayload(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Versión estándar',
            'price' => 100000,
            'is_active' => true,
            'is_visible' => true,
        ], $overrides);
    }

    private function compatibilityPayload($brand, $model, $version, $system): array
    {
        return [
            'vehicle_brand_id' => $brand->id,
            'vehicle_model_id' => $model->id,
            'vehicle_version_id' => $version->id,
            'vehicle_multimedia_system_id' => $system->id,
            'year_from' => 2014,
            'year_to' => 2018,
        ];
    }

    private function categoryWithField(
        string $scope,
        string $key,
        bool $required = false,
        ?array $options = null
    ): array {
        $category = ProductCategory::create([
            'name' => 'Categoría '.fake()->unique()->word(),
            'slug' => fake()->unique()->slug(),
            'is_active' => true,
        ]);
        $field = $category->fields()->create([
            'name' => $key,
            'field_key' => $key,
            'type' => $options ? 'select' : 'text',
            'scope' => $scope,
            'options' => $options,
            'is_required' => $required,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        return [$category, $field];
    }

    private function vehicleCatalog(string $prefix = 'BMW'): array
    {
        $slug = strtolower($prefix).'-'.fake()->unique()->numerify('###');
        $brand = VehicleBrand::create(['name' => "{$prefix} {$slug}", 'slug' => $slug, 'is_active' => true]);
        $model = VehicleModel::create([
            'vehicle_brand_id' => $brand->id,
            'name' => "Model {$slug}",
            'slug' => "model-{$slug}",
            'is_active' => true,
        ]);
        $version = VehicleVersion::create([
            'vehicle_model_id' => $model->id,
            'name' => 'Generation',
            'year_from' => 2012,
            'year_to' => 2020,
            'is_active' => true,
        ]);
        $system = VehicleMultimediaSystem::create([
            'vehicle_brand_id' => $brand->id,
            'name' => "OEM {$slug}",
            'slug' => "oem-{$slug}",
            'is_active' => true,
        ]);

        return [$brand, $model, $version, $system];
    }

    private function product(array $overrides = []): Product
    {
        return Product::create(array_replace([
            'name' => 'Producto '.fake()->unique()->word(),
            'normalized_name' => fake()->word(),
            'slug' => fake()->unique()->slug(),
            'price' => 100000,
            'is_active' => true,
            'is_visible' => true,
        ], $overrides));
    }

    private function variant(Product $product, array $overrides = []): ProductVariant
    {
        return $product->variants()->create(array_replace([
            'name' => 'Variante',
            'normalized_name' => 'variante',
            'price' => 100000,
            'is_active' => true,
            'is_visible' => true,
        ], $overrides));
    }
}
