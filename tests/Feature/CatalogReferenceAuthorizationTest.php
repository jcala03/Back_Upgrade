<?php

namespace Tests\Feature;

use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\User;
use App\Models\VehicleBrand;
use App\Models\VehicleModel;
use App\Models\VehicleMultimediaSystem;
use App\Models\VehicleVersion;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CatalogReferenceAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_INDEX_ENDPOINTS = [
        '/api/admin/product-categories',
        '/api/admin/product-brands',
        '/api/admin/vehicle-brands',
        '/api/admin/vehicle-models',
        '/api/admin/vehicle-versions',
        '/api/admin/vehicle-multimedia-systems',
    ];

    private const STORE_ENDPOINTS = [
        '/api/admin/product-categories',
        '/api/admin/product-brands',
        '/api/admin/vehicle-brands',
        '/api/admin/vehicle-models',
        '/api/admin/vehicle-versions',
        '/api/admin/vehicle-multimedia-systems',
    ];

    public function createApplication()
    {
        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        if ($connection = getenv('TEST_DB_CONNECTION')) {
            $app['config']->set('database.default', $connection);
            $app['config']->set(
                "database.connections.{$connection}.database",
                getenv('TEST_DB_DATABASE') ?: 'upgrade_authorization_test'
            );
        }

        return $app;
    }

    public function test_guest_cannot_access_administrative_reference_endpoints(): void
    {
        foreach (self::ADMIN_INDEX_ENDPOINTS as $endpoint) {
            $this->getJson($endpoint)->assertUnauthorized();
        }

        $this->postJson('/api/admin/vehicle-multimedia-systems', [])->assertUnauthorized();
    }

    public function test_user_cannot_read_administrative_catalog_references(): void
    {
        $this->actingAsRole('user');

        foreach (self::ADMIN_INDEX_ENDPOINTS as $endpoint) {
            $this->getJson($endpoint)->assertForbidden();
        }
    }

    public function test_user_without_product_permissions_cannot_read_references(): void
    {
        $this->actingAsRole('viewer');

        foreach (self::ADMIN_INDEX_ENDPOINTS as $endpoint) {
            $this->getJson($endpoint)->assertForbidden();
        }
    }

    public function test_sales_cannot_create_catalog_references(): void
    {
        $this->actingAsRole('user');

        foreach (self::STORE_ENDPOINTS as $endpoint) {
            $this->postJson($endpoint, [])->assertForbidden();
        }
    }

    public function test_sales_cannot_update_or_delete_catalog_references(): void
    {
        [$brand, $model, $version, $system] = $this->vehicleCatalog();
        $category = ProductCategory::create([
            'name' => 'Categoría '.uniqid(),
            'slug' => 'categoria-'.uniqid(),
            'is_active' => true,
        ]);
        $productBrand = ProductBrand::create([
            'name' => 'Marca '.uniqid(),
            'slug' => 'marca-'.uniqid(),
            'is_active' => true,
        ]);
        $this->actingAsRole('user');

        $this->postJson("/api/admin/product-categories/{$category->id}", [])->assertForbidden();
        $this->deleteJson("/api/admin/product-categories/{$category->id}")->assertForbidden();
        $this->postJson("/api/admin/product-brands/{$productBrand->id}", [])->assertForbidden();
        $this->deleteJson("/api/admin/product-brands/{$productBrand->id}")->assertForbidden();
        $this->postJson("/api/admin/vehicle-brands/{$brand->id}", [])->assertForbidden();
        $this->deleteJson("/api/admin/vehicle-brands/{$brand->id}")->assertForbidden();
        $this->postJson("/api/admin/vehicle-models/{$model->id}", [])->assertForbidden();
        $this->deleteJson("/api/admin/vehicle-models/{$model->id}")->assertForbidden();
        $this->postJson("/api/admin/vehicle-versions/{$version->id}", [])->assertForbidden();
        $this->deleteJson("/api/admin/vehicle-versions/{$version->id}")->assertForbidden();
        $this->postJson("/api/admin/vehicle-multimedia-systems/{$system->id}", [])->assertForbidden();
        $this->deleteJson("/api/admin/vehicle-multimedia-systems/{$system->id}")->assertForbidden();
    }

    public function test_multimedia_sync_requires_products_update_permission(): void
    {
        [, , $version, $system] = $this->vehicleCatalog();
        $payload = ['multimedia_systems' => [[
            'vehicle_multimedia_system_id' => $system->id,
            'year_from' => 2015,
            'year_to' => 2018,
        ]]];

        $this->actingAsRole('user');
        $this->putJson("/api/admin/vehicle-versions/{$version->id}/multimedia-systems", $payload)
            ->assertForbidden();

        $this->actingAsRole('admin');
        $this->putJson("/api/admin/vehicle-versions/{$version->id}/multimedia-systems", $payload)
            ->assertOk()
            ->assertJsonPath('data.multimedia_systems.0.id', $system->id);
    }

    public function test_admin_retains_catalog_reference_crud(): void
    {
        $this->actingAsRole('admin');

        $categoryId = $this->postJson('/api/admin/product-categories', ['name' => 'Audio'])
            ->assertCreated()->json('data.id');
        $this->postJson("/api/admin/product-categories/{$categoryId}", ['name' => 'Audio premium'])
            ->assertOk()->assertJsonPath('data.name', 'Audio premium');
        $this->deleteJson("/api/admin/product-categories/{$categoryId}")->assertOk();

        $productBrandId = $this->postJson('/api/admin/product-brands', ['name' => 'Marca producto'])
            ->assertCreated()->json('data.id');
        $this->postJson("/api/admin/product-brands/{$productBrandId}", ['name' => 'Marca producto dos'])
            ->assertOk()->assertJsonPath('data.name', 'Marca producto dos');
        $this->deleteJson("/api/admin/product-brands/{$productBrandId}")->assertOk();

        $vehicleBrandId = $this->postJson('/api/admin/vehicle-brands', ['name' => 'Marca vehículo'])
            ->assertCreated()->json('data.id');
        $this->postJson("/api/admin/vehicle-brands/{$vehicleBrandId}", ['name' => 'Marca vehículo dos'])
            ->assertOk()->assertJsonPath('data.name', 'Marca vehículo dos');

        $vehicleModelId = $this->postJson('/api/admin/vehicle-models', [
            'vehicle_brand_id' => $vehicleBrandId,
            'name' => 'Modelo uno',
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/admin/vehicle-models/{$vehicleModelId}", [
            'vehicle_brand_id' => $vehicleBrandId,
            'name' => 'Modelo dos',
        ])->assertOk()->assertJsonPath('data.name', 'Modelo dos');

        $vehicleVersionId = $this->postJson('/api/admin/vehicle-versions', [
            'vehicle_model_id' => $vehicleModelId,
            'name' => 'Fase uno',
            'year_from' => 2015,
            'year_to' => 2020,
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/admin/vehicle-versions/{$vehicleVersionId}", [
            'vehicle_model_id' => $vehicleModelId,
            'name' => 'Fase dos',
            'year_from' => 2015,
            'year_to' => 2020,
        ])->assertOk()->assertJsonPath('data.name', 'Fase dos');

        $systemId = $this->postJson('/api/admin/vehicle-multimedia-systems', [
            'vehicle_brand_id' => $vehicleBrandId,
            'name' => 'NBT',
            'code' => 'NBT-01',
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/admin/vehicle-multimedia-systems/{$systemId}", [
            'vehicle_brand_id' => $vehicleBrandId,
            'name' => 'NBT EVO',
            'code' => 'NBT-02',
        ])->assertOk()->assertJsonPath('data.name', 'NBT EVO');
        $this->deleteJson("/api/admin/vehicle-multimedia-systems/{$systemId}")->assertOk();

        $this->deleteJson("/api/admin/vehicle-versions/{$vehicleVersionId}")->assertOk();
        $this->deleteJson("/api/admin/vehicle-models/{$vehicleModelId}")->assertOk();
        $this->deleteJson("/api/admin/vehicle-brands/{$vehicleBrandId}")->assertOk();
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function vehicleCatalog(): array
    {
        $brand = VehicleBrand::create([
            'name' => 'BMW '.uniqid(),
            'slug' => 'bmw-'.uniqid(),
            'is_active' => true,
        ]);
        $model = VehicleModel::create([
            'vehicle_brand_id' => $brand->id,
            'name' => 'Serie 3',
            'slug' => 'serie-3-'.uniqid(),
            'is_active' => true,
        ]);
        $version = VehicleVersion::create([
            'vehicle_model_id' => $model->id,
            'name' => 'F30',
            'year_from' => 2012,
            'year_to' => 2019,
            'is_active' => true,
        ]);
        $system = VehicleMultimediaSystem::create([
            'vehicle_brand_id' => $brand->id,
            'name' => 'NBT '.uniqid(),
            'slug' => 'nbt-'.uniqid(),
            'is_active' => true,
        ]);

        return [$brand, $model, $version, $system];
    }
}
