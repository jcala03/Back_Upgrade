<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductCategoryField;
use App\Models\ProductVariant;
use App\Models\ProductVariantCompatibility;
use App\Models\ProductVariantSpecValue;
use App\Models\VehicleBrand;
use App\Models\VehicleModel;
use App\Models\VehicleMultimediaSystem;
use App\Models\VehicleVersion;
use App\Services\ProductConfigurationValidator;
use App\Services\ProductConfiguratorRegistry;
use Illuminate\Config\Repository;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\MySqlConnection;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/** Real validator/registry/models; only the persistence boundary is doubled.
 * PDO is a refusing closure: no MySQL database can be opened by these tests.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class MultimediaPurchaseValidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $app = new Application(dirname(__DIR__, 2));
        $app->instance('env', 'testing');
        $app->instance('config', new Repository);
        $app->instance('validator', new Factory(
            new Translator(new ArrayLoader, 'es'), $app
        ));
        Facade::setFacadeApplication($app);
        $connection = new MySqlConnection(fn () => throw new \RuntimeException('Database access forbidden'), '__no_database__');
        Model::setConnectionResolver(new ConnectionResolver(['fixture' => $connection]));
        Model::getConnectionResolver()->setDefaultConnection('fixture');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function fixture(string $profile = 'vehicle-oem-memory-v1'): array
    {
        $product = (new Product)->forceFill(['id' => 100, 'category_id' => 6, 'name' => 'Multimedia',
            'is_active' => true, 'is_visible' => true]);
        $variant = Mockery::mock(ProductVariant::class)->makePartial();
        $variant->forceFill(['id' => 200, 'product_id' => 100, 'sku' => 'ISOLATED-KIT', 'name' => 'Physical kit',
            'is_active' => true, 'is_visible' => true, 'price' => 1200000]);
        $brand = (new VehicleBrand)->forceFill(['id' => 10, 'name' => 'BMW', 'is_active' => true]);
        $model = (new VehicleModel)->forceFill(['id' => 20, 'vehicle_brand_id' => 10, 'name' => 'Serie 3', 'is_active' => true]);
        $system = (new VehicleMultimediaSystem)->forceFill(['id' => 40, 'vehicle_brand_id' => 10, 'name' => 'CIC', 'is_active' => true]);
        $system->setRelation('pivot', new Pivot(['year_from' => 2012, 'year_to' => 2013]));
        $version = (new VehicleVersion)->forceFill(['id' => 30, 'vehicle_model_id' => 20, 'name' => 'F30',
            'year_from' => 2012, 'year_to' => 2018, 'is_active' => true]);
        $version->setRelation('multimediaSystems', new Collection([$system]));
        $row = (new ProductVariantCompatibility)->forceFill(['id' => 300, 'product_variant_id' => 200,
            'vehicle_brand_id' => 10, 'vehicle_model_id' => 20, 'vehicle_version_id' => 30, 'vehicle_multimedia_system_id' => 40]);
        foreach (['vehicleBrand' => $brand, 'vehicleModel' => $model, 'vehicleVersion' => $version, 'vehicleMultimediaSystem' => $system] as $key => $value) {
            $row->setRelation($key, $value);
        }
        $relation = Mockery::mock(HasMany::class);
        $relation->shouldReceive('with')->andReturnSelf();
        $relation->shouldReceive('getQuery')->andReturn($row->newQuery());
        $relation->shouldReceive('get')->andReturn(new Collection([$row]));
        $variant->shouldReceive('vehicleCompatibilities')->andReturn($relation);
        $specs = new Collection;
        foreach (['ram_gb' => 8, 'almacenamiento_gb' => 128] as $key => $number) {
            $field = new ProductCategoryField(['field_key' => $key, 'product_category_id' => 6,
                'type' => 'number', 'scope' => 'variant', 'filter_unit' => 'GB', 'is_active' => true]);
            $value = new ProductVariantSpecValue(['value_number' => $number]);
            $value->setRelation('field', $field);
            $specs->push($value);
        }
        $variant->setRelation('specValues', $specs);
        $registry = Mockery::mock(ProductConfiguratorRegistry::class);
        $registry->shouldReceive('registration')->andReturn(['purchase_enabled' => true,
            'profile_id' => $profile, 'profile_version' => 1]);
        $registry->shouldReceive('variantPurchaseApproved')->andReturn(true);
        $service = new ProductConfigurationValidator($registry);
        $context = ['schema_version' => 1, 'profile_id' => $profile, 'profile_version' => 1,
            'vehicle_brand_id' => 10, 'vehicle_model_id' => 20, 'vehicle_version_id' => 30,
            'vehicle_multimedia_system_id' => 40, 'year' => 2013];
        if ($profile === 'vehicle-oem-memory-v1') {
            $context += ['ram_gb' => 8, 'almacenamiento_gb' => 128];
        }

        return compact('product', 'variant', 'service', 'context');
    }

    public function test_real_validator_rebuilds_memory_snapshot_and_never_accepts_commercial_client_data(): void
    {
        extract($this->fixture());
        $snapshot = $service->snapshot($product, $variant, ['product_variant_id' => 200, 'configuration_context' => $context]);
        $this->assertSame(8, $snapshot['ram_gb']);
        $this->assertSame('ISOLATED-KIT', $snapshot['variant_sku']);
        $this->assertSame('BMW', $snapshot['vehicle_brand_name']);
        $this->assertArrayNotHasKey('price', $snapshot);
        $this->assertSame($snapshot, $service->snapshot($product, $variant, ['product_variant_id' => 200, 'configuration_context' => $context]));
        $this->expectException(ValidationException::class);
        $service->snapshot($product, $variant, ['product_variant_id' => 200, 'configuration_context' => $context + ['price' => 1]]);
    }

    #[DataProvider('invalidMemory')]
    public function test_memory_profile_still_rejects_missing_or_manipulated_values(array $changes): void
    {
        extract($this->fixture());
        $context = array_replace($context, $changes);
        $this->expectException(ValidationException::class);
        $service->snapshot($product, $variant, ['product_variant_id' => 200, 'configuration_context' => $context]);
    }

    public static function invalidMemory(): array
    {
        return ['missing' => [['ram_gb' => null]], 'wrong' => [['ram_gb' => 4]], 'storage' => [['almacenamiento_gb' => 256]],
            'string is not a verified integer' => [['ram_gb' => '8']]];
    }

    public function test_reference_profile_has_same_sku_and_vehicle_snapshot_without_fabricated_memory(): void
    {
        extract($this->fixture('vehicle-oem-reference-v1'));
        $snapshot = $service->snapshot($product, $variant, ['product_variant_id' => 200, 'configuration_context' => $context]);
        $this->assertArrayNotHasKey('ram_gb', $snapshot);
        $this->assertArrayNotHasKey('almacenamiento_gb', $snapshot);
        $this->assertSame(200, $snapshot['variant_id']);
        $this->assertSame('CIC', $snapshot['vehicle_multimedia_system_name']);
        $this->expectException(ValidationException::class);
        $service->snapshot($product, $variant, ['product_variant_id' => 200, 'configuration_context' => $context + ['ram_gb' => 8]]);
    }

    public function test_missing_year_cannot_bypass_partial_technical_coverage(): void
    {
        extract($this->fixture());
        $context['year'] = null;
        $this->expectException(ValidationException::class);
        $service->snapshot($product, $variant, ['product_variant_id' => 200, 'configuration_context' => $context]);
    }

    #[DataProvider('unavailableReferences')]
    public function test_wrong_variant_and_unpublished_reference_are_rejected_before_any_persistence(string $case): void
    {
        extract($this->fixture());
        match ($case) {
            'foreign' => $variant->product_id = 999,
            'hidden' => $variant->is_visible = false,
            'inactive' => $variant->is_active = false,
            'hidden parent' => $product->is_visible = false,
            'inactive parent' => $product->is_active = false,
        };
        $this->expectException(ValidationException::class);
        $service->snapshot($product, $variant, ['product_variant_id' => 200, 'configuration_context' => $context]);
    }

    public static function unavailableReferences(): array
    {
        return array_combine(['foreign', 'hidden', 'inactive', 'hidden parent', 'inactive parent'],
            array_map(fn ($case) => [$case], ['foreign', 'hidden', 'inactive', 'hidden parent', 'inactive parent']));
    }

    public function test_production_registry_needs_separate_approval_review_and_exact_catalog(): void
    {
        app()->instance('env', 'production');
        $identity = ['environment' => 'production', 'driver' => 'mysql', 'host' => 'fixture.local',
            'port' => '3306', 'database' => 'isolated_identity'];
        $registry = new class extends ProductConfiguratorRegistry
        {
            public function catalogIdentity(Product $product): array
            {
                return ['environment' => 'production', 'driver' => 'mysql', 'host' => 'fixture.local',
                    'port' => '3306', 'database' => 'isolated_identity'];
            }
        };
        $product = (new Product)->forceFill(['id' => 123, 'name' => 'Verified reference', 'sku' => 'REAL-KIT']);
        config(['product_configurator' => ['preview_enabled' => true, 'purchase_enabled' => true,
            'production_approved' => false, 'catalog' => $identity,
            'profiles' => ['vehicle-oem-reference-v1' => ['version' => 1, 'enabled' => true]],
            'products' => [123 => ['profile_id' => 'vehicle-oem-reference-v1', 'profile_version' => 1, 'reviewed' => true, 'approved_variant_ids' => [200]]]]]);
        $this->assertFalse($registry->registration($product)['purchase_enabled']);
        config(['product_configurator.production_approved' => true]);
        app()->instance('db', new class
        {
            public function connection($name): self
            {
                return $this;
            }

            public function getSchemaBuilder(): self
            {
                return $this;
            }

            public function hasColumn($table, $column): bool
            {
                return $table === 'order_items' && $column === 'configuration_context';
            }
        });
        $this->assertTrue($registry->registration($product)['purchase_enabled']);
        config(['product_configurator.products.123.reviewed' => false]);
        $this->assertFalse($registry->registration($product)['purchase_enabled']);
        config(['product_configurator.products.123.reviewed' => true, 'product_configurator.catalog.database' => 'different_catalog']);
        $this->assertFalse($registry->registration($product)['purchase_enabled']);
        config(['product_configurator.catalog' => $identity]);
        $product->name = 'DEMO - not merchandise';
        $this->assertFalse($registry->registration($product)['purchase_enabled']);
    }

    public function test_required_oem_category_without_enrollment_cannot_fall_back_to_traditional_purchase(): void
    {
        config(['product_configurator.products' => []]);
        $product = (new Product)->forceFill(['id' => 100, 'category_id' => 6]);
        $product->setRelation('productCategory', new ProductCategory(['requires_oem_compatibility' => true]));
        $registry = new ProductConfiguratorRegistry;
        $this->assertTrue($registry->registration($product)['required']);
        $this->assertFalse($registry->registration($product)['purchase_enabled']);
        $product->productCategory->requires_oem_compatibility = false;
        $this->assertNull($registry->registration($product));
        $product->productCategory->requires_oem_compatibility = true;
        $this->expectException(ValidationException::class);
        (new ProductConfigurationValidator($registry))->snapshot($product, null, []);
    }
}
