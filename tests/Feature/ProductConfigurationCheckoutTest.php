<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Models\VehicleBrand;
use App\Models\VehicleModel;
use App\Models\VehicleMultimediaSystem;
use App\Models\VehicleVersion;
use App\Services\OrderService;
use App\Services\ProductConfiguratorRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductConfigurationCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Http::preventStrayRequests();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->assertSame('upgrade_test', DB::selectOne('SELECT DATABASE() AS name')->name);
    }

    private function fixture(string $brandName = 'BMW', string $oemName = 'CIC'): array
    {
        $category = ProductCategory::create(['name' => 'Fixture', 'slug' => 'fixture', 'is_active' => true]);
        $product = Product::create(['name' => 'Fixture configurable', 'slug' => 'fixture-configurable', 'price' => 1,
            'category_id' => $category->id, 'is_active' => true, 'is_visible' => true, 'compatibility_type' => 'vehicle_specific', 'requires_shipping' => true])->fresh();
        $variant = $product->variants()->create(['name' => 'Referencia verificada', 'normalized_name' => 'referencia verificada', 'sku' => 'FIXTURE-COMMERCIAL',
            'price' => 1200000, 'is_active' => true, 'is_visible' => true, 'is_default' => true]);
        foreach (['ram_gb' => 8, 'almacenamiento_gb' => 128] as $key => $value) {
            $field = $category->fields()->create(['name' => $key, 'field_key' => $key, 'type' => 'number', 'scope' => 'variant', 'filter_unit' => 'GB', 'is_active' => true]);
            $variant->specValues()->create(['product_category_field_id' => $field->id, 'value_number' => $value]);
        }
        $brand = VehicleBrand::create(['name' => $brandName, 'slug' => strtolower($brandName), 'is_active' => true]);
        $model = VehicleModel::create(['vehicle_brand_id' => $brand->id, 'name' => 'Modelo verificado', 'slug' => 'modelo-verificado', 'is_active' => true]);
        $version = VehicleVersion::create(['vehicle_model_id' => $model->id, 'name' => 'Generación verificada', 'year_from' => 2012, 'year_to' => 2018, 'is_active' => true]);
        $oem = VehicleMultimediaSystem::create(['vehicle_brand_id' => $brand->id, 'name' => $oemName, 'slug' => strtolower($oemName), 'code' => strtolower($oemName), 'is_active' => true]);
        $context = ['schema_version' => 1, 'profile_id' => 'vehicle-oem-memory-v1', 'profile_version' => 1,
            'vehicle_brand_id' => $brand->id, 'vehicle_model_id' => $model->id, 'vehicle_version_id' => $version->id,
            'vehicle_multimedia_system_id' => $oem->id, 'year' => 2015, 'ram_gb' => 8, 'almacenamiento_gb' => 128];
        $variant->vehicleCompatibilities()->create(array_intersect_key($context, array_flip(['vehicle_brand_id', 'vehicle_model_id', 'vehicle_version_id', 'vehicle_multimedia_system_id'])));
        $branch = Branch::create(['name' => 'Sede fixture', 'code' => 'FIXTURE', 'slug' => 'fixture', 'city' => 'Barranquilla', 'is_active' => true]);
        $item = InventoryItem::create(['product_id' => null, 'product_variant_id' => $variant->id]);
        $stock = InventoryStock::create(['branch_id' => $branch->id, 'inventory_item_id' => $item->id, 'quantity' => 3]);
        config(['product_configurator.catalog' => app(ProductConfiguratorRegistry::class)->catalogIdentity($product),
            'product_configurator.products' => [$product->id => ['profile_id' => 'vehicle-oem-memory-v1', 'profile_version' => 1]],
            'product_configurator.preview_enabled' => true, 'product_configurator.purchase_enabled' => true]);
        $payload = ['customer_name' => 'Cliente de prueba', 'customer_email' => 'fixture@example.test', 'customer_phone' => '3001234567',
            'items' => [['product_id' => $product->id, 'product_variant_id' => $variant->id, 'quantity' => 1, 'configuration_context' => $context]]];

        return compact('product', 'variant', 'context', 'payload', 'stock', 'branch', 'brand', 'model', 'version', 'oem');
    }

    public function test_real_snapshot_server_price_public_and_admin_resources_and_rename(): void
    {
        extract($this->fixture());
        $product->update(['name' => 'Nuevo nombre', 'slug' => 'nuevo-slug']);
        $variant->update(['price' => 1300000]);
        $response = $this->withHeader('Idempotency-Key', 'snapshot')->postJson('/api/orders', $payload)->assertCreated()
            ->assertJsonPath('data.items.0.unit_price', 1300000)
            ->assertJsonPath('data.items.0.configuration_context.vehicle_multimedia_system_name', 'CIC')
            ->assertJsonMissingPath('data.items.0.unit_cost');
        $order = Order::sole();
        $snapshot = $order->items()->sole()->configuration_context;
        $this->assertSame($variant->id, $snapshot['variant_id']);
        $this->assertSame(8, $snapshot['ram_gb']);
        $this->assertSame(3, $stock->fresh()->quantity);
        $this->assertDatabaseCount('inventory_movements', 0);
        $brand->update(['name' => 'Marca renombrada']);
        $this->getJson('/api/checkout/orders/'.$response->json('public_token'))->assertOk()
            ->assertJsonPath('data.items.0.configuration_context', $snapshot);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson('/api/admin/orders/'.$order->id)->assertOk()->assertJsonPath('data.items.0.configuration_context', $snapshot);
        Http::assertNothingSent();
    }

    #[DataProvider('invalidSelections')]
    public function test_invalid_or_manipulated_selections_write_nothing(string $case): void
    {
        extract($this->fixture());
        $line = &$payload['items'][0];
        switch ($case) {
            case 'omitted': unset($line['configuration_context']);
                break;
            case 'empty': $line['configuration_context'] = [];
                break;
            case 'unknown': $line['configuration_context']['profile_id'] = 'unknown';
                break;
            case 'wrong_version': $line['configuration_context']['profile_version'] = 2;
                break;
            case 'no_variant': unset($line['product_variant_id']);
                break;
            case 'foreign_variant':
                $other = Product::create(['name' => 'Otro', 'slug' => 'otro', 'price' => 1]);
                $line['product_variant_id'] = $other->variants()->create(['name' => 'Otro', 'normalized_name' => 'otro', 'price' => 1])->id;
                break;
            case 'bad_oem': $line['configuration_context']['vehicle_multimedia_system_id'] += 999;
                break;
            case 'bad_year': $line['configuration_context']['year'] = 2020;
                break;
            case 'bad_ram': $line['configuration_context']['ram_gb'] = 4;
                break;
            case 'bad_storage': $line['configuration_context']['almacenamiento_gb'] = 256;
                break;
            case 'metadata': $product->productCategory->fields()->where('field_key', 'ram_gb')->update(['scope' => 'product']);
                break;
            case 'catalog_changed': $variant->specValues()->update(['value_number' => 16]);
                break;
            case 'inactive_product': $product->update(['is_active' => false]);
                break;
            case 'hidden_product': $product->update(['is_visible' => false]);
                break;
            case 'inactive_variant': $variant->update(['is_active' => false]);
                break;
            case 'hidden_variant': $variant->update(['is_visible' => false]);
                break;
            case 'inactive_oem': $oem->update(['is_active' => false]);
                break;
            case 'stock_zero': $stock->update(['quantity' => 0]);
                break;
            case 'stock_short': $line['quantity'] = 4;
                break;
            case 'disabled': config(['product_configurator.purchase_enabled' => false]);
                break;
            case 'disabled_profile': config(['product_configurator.profiles.vehicle-oem-memory-v1.enabled' => false]);
                break;
            case 'wrong_catalog': config(['product_configurator.catalog.database' => 'another_database']);
                break;
            case 'injected_price': $line['configuration_context']['price'] = 1;
                break;
            case 'ambiguous_without_reference':
                $product->variants()->create(['name' => 'Otro kit', 'normalized_name' => 'otro kit', 'price' => 1, 'is_active' => true, 'is_visible' => true]);
                unset($line['product_variant_id']);
                break;
            case 'cross_row':
                $variant->vehicleCompatibilities()->update(['vehicle_version_id' => null]);
                $variant->vehicleCompatibilities()->create(['vehicle_brand_id' => $brand->id, 'vehicle_model_id' => $model->id, 'vehicle_version_id' => $version->id]);
                break;
        }
        $this->withHeader('Idempotency-Key', 'invalid')->postJson('/api/orders', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public static function invalidSelections(): array
    {
        return array_map(fn ($case) => [$case], ['omitted', 'empty', 'unknown', 'wrong_version', 'no_variant', 'foreign_variant', 'bad_oem', 'bad_year',
            'bad_ram', 'bad_storage', 'metadata', 'catalog_changed', 'inactive_product', 'hidden_product', 'inactive_variant', 'hidden_variant', 'inactive_oem',
            'stock_zero', 'stock_short', 'disabled', 'disabled_profile', 'wrong_catalog', 'injected_price', 'ambiguous_without_reference', 'cross_row']);
    }

    public function test_shared_sku_preserves_two_oems_but_sums_stock_and_idempotency_includes_context(): void
    {
        extract($this->fixture());
        $nbt = VehicleMultimediaSystem::create(['vehicle_brand_id' => $brand->id, 'name' => 'NBT', 'slug' => 'nbt', 'code' => 'nbt', 'is_active' => true]);
        $variant->vehicleCompatibilities()->create(['vehicle_brand_id' => $brand->id, 'vehicle_model_id' => $model->id, 'vehicle_version_id' => $version->id, 'vehicle_multimedia_system_id' => $nbt->id]);
        $payload['items'][] = $payload['items'][0];
        $payload['items'][1]['configuration_context']['vehicle_multimedia_system_id'] = $nbt->id;
        $this->withHeader('Idempotency-Key', 'shared')->postJson('/api/orders', $payload)->assertCreated()->assertJsonCount(2, 'data.items');
        $this->withHeader('Idempotency-Key', 'shared')->postJson('/api/orders', $payload)->assertOk();
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 2);
        $this->assertSame(1, Order::sole()->items->pluck('product_variant_id')->unique()->count());
        $payload['items'][0]['configuration_context']['vehicle_multimedia_system_id'] = $nbt->id;
        $this->withHeader('Idempotency-Key', 'shared')->postJson('/api/orders', $payload)->assertUnprocessable()->assertJsonPath('code', 'IDEMPOTENCY_CONFLICT');
        $payload['items'][0]['quantity'] = 2;
        $payload['items'][1]['quantity'] = 2;
        $this->withHeader('Idempotency-Key', 'short')->postJson('/api/orders', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(3, $stock->fresh()->quantity);
    }

    public function test_other_manufacturer_and_optional_year_are_real_registered_data(): void
    {
        extract($this->fixture('Toyota', 'Touch'));
        unset($payload['items'][0]['configuration_context']['year']);
        $this->withHeader('Idempotency-Key', 'toyota')->postJson('/api/orders', $payload)->assertCreated()
            ->assertJsonPath('data.items.0.configuration_context.vehicle_brand_name', 'Toyota')
            ->assertJsonPath('data.items.0.configuration_context.year', null);
    }

    public function test_same_sku_contexts_reserve_only_their_combined_quantity_and_replay_is_safe(): void
    {
        extract($this->fixture());
        $other = VehicleMultimediaSystem::create(['vehicle_brand_id' => $brand->id, 'name' => 'NBT', 'slug' => 'nbt', 'is_active' => true]);
        $variant->vehicleCompatibilities()->create(['vehicle_brand_id' => $brand->id, 'vehicle_model_id' => $model->id, 'vehicle_version_id' => $version->id, 'vehicle_multimedia_system_id' => $other->id]);
        $payload['items'][] = $payload['items'][0];
        $payload['items'][1]['configuration_context']['vehicle_multimedia_system_id'] = $other->id;
        $created = $this->withHeader('Idempotency-Key', 'two-contexts')->postJson('/api/orders', $payload)->assertCreated();
        $order = Order::sole();
        $order->update(['branch_id' => $branch->id, 'fulfillment_type' => 'pickup']);
        app(OrderService::class)->transition($order, 'confirmed', null);
        app(OrderService::class)->transition($order->fresh(), 'confirmed', null);
        $this->assertSame(1, $stock->fresh()->quantity);
        $this->assertDatabaseCount('inventory_movements', 2);
        $this->assertDatabaseCount('order_items', 2);
        $this->getJson('/api/checkout/orders/'.$created->json('public_token'))->assertOk()->assertJsonCount(2, 'data.items');
    }

    public function test_snapshot_migration_cannot_be_rolled_back_over_existing_history(): void
    {
        extract($this->fixture());
        $this->withHeader('Idempotency-Key', 'history')->postJson('/api/orders', $payload)->assertCreated();
        $migration = require database_path('migrations/2026_10_28_000001_add_configuration_context_to_order_items.php');
        try {
            $migration->down();
            $this->fail('Historical snapshots must not be dropped.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('historical', $exception->getMessage());
        }
        $this->assertNotNull(Order::sole()->items()->sole()->configuration_context);
    }

    public function test_first_reservation_revalidates_catalog_and_retry_keeps_same_order_snapshot(): void
    {
        extract($this->fixture());
        config(['services.wompi' => ['base_url' => 'https://sandbox.wompi.co/v1', 'checkout_url' => 'https://checkout.wompi.co/p/',
            'public_key' => 'pub_test_fixture', 'private_key' => 'prv_test_fixture', 'integrity_secret' => 'test_integrity_fixture',
            'events_secret' => 'test_events_fixture', 'redirect_url' => 'https://shop.example.test/checkout/payment/return', 'reservation_minutes' => 30]]);
        $created = $this->withHeader('Idempotency-Key', 'retry-order')->postJson('/api/orders', $payload)->assertCreated();
        $token = $created->json('public_token');
        $this->postJson('/api/checkout/orders/'.$token.'/pickup', ['branch_slug' => $branch->slug])->assertOk();
        $snapshot = Order::sole()->items()->sole()->configuration_context;
        $variant->update(['is_visible' => false]);
        $this->init($token, 'pay-blocked')->assertStatus(409)->assertJsonPath('code', 'ORDER_NOT_READY_FOR_PAYMENT');
        $this->assertSame(3, $stock->fresh()->quantity);
        $this->assertDatabaseCount('payments', 0);
        $variant->update(['is_visible' => true]);
        $this->init($token, 'pay-1')->assertCreated();
        $this->init($token, 'pay-1')->assertOk();
        Payment::sole()->update(['status' => 'failed', 'provider_status' => 'DECLINED']);
        $this->init($token, 'pay-2')->assertCreated();
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertSame(2, $stock->fresh()->quantity);
        $this->assertSame($snapshot, Order::sole()->items()->sole()->configuration_context);
        Http::assertNothingSent();
    }

    private function init(string $token, string $key): TestResponse
    {
        return $this->call('POST', '/api/checkout/orders/'.$token.'/payments/wompi', [], [], [], [
            'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => $key,
        ], '{}');
    }
}
