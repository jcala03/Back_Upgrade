<?php

namespace Tests\Isolated;

use App\Mail\OrderCreatedMail;
use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\ProductConfigurationValidator;
use App\Services\ProductConfiguratorRegistry;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MultimediaFixture;
use Tests\Support\MultimediaSqliteApplication;

/** Opt-in SQL/HTTP integration: --no-configuration; never Tests\TestCase/RefreshDatabase. */
class MultimediaPersistenceTest extends TestCase
{
    public function createApplication()
    {
        return MultimediaSqliteApplication::boot();
    }

    protected function setUp(): void
    {
        parent::setUp();
        MultimediaSqliteApplication::migrate($this->app);
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_sql_harness_has_no_named_database_and_keeps_real_foreign_keys(): void
    {
        $this->assertSame(['sqlite'], array_keys(config('database.connections')));
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->assertSame('', DB::selectOne('PRAGMA database_list')->file);
        $this->assertSame(1, (int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        config(['database.default' => 'mysql']);
        try {
            MultimediaSqliteApplication::guard($this->app);
            $this->fail('A MySQL default must be rejected before connection.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Unsafe isolated', $exception->getMessage());
        } finally {
            config(['database.default' => 'sqlite']);
        }
    }

    #[DataProvider('profiles')]
    public function test_real_sql_public_catalog_checkout_snapshot_and_crm(string $profile, string $brand): void
    {
        extract(MultimediaFixture::create($profile, $brand));
        $this->getJson('/api/products/'.$product->slug)->assertOk()
            ->assertJsonPath('data.configuration.profile_id', $profile)
            ->assertJsonPath('data.variants.0.sku', $variant->sku)
            ->assertJsonPath('data.variants.0.price', 1200000)
            ->assertJsonMissingPath('data.variants.0.cost_price');
        $response = $this->withHeader('Idempotency-Key', 'real-sql')->postJson('/api/orders', $payload)->assertCreated()
            ->assertJsonPath('data.items.0.unit_price', 1200000);
        $raw = DB::table('order_items')->sole();
        $snapshot = json_decode($raw->configuration_context, true, flags: JSON_THROW_ON_ERROR);
        foreach ($context as $key => $value) {
            $this->assertSame($value, $snapshot[$key]);
        }
        $this->assertSame($variant->id, $snapshot['variant_id']);
        $this->assertSame($variant->sku, $snapshot['variant_sku']);
        $this->assertSame($brand->name, $snapshot['vehicle_brand_name']);
        if ($profile === 'vehicle-oem-reference-v1') {
            $this->assertArrayNotHasKey('ram_gb', $snapshot);
            $this->assertArrayNotHasKey('almacenamiento_gb', $snapshot);
        }
        $this->getJson('/api/checkout/orders/'.$response->json('public_token'))->assertOk()
            ->assertJsonPath('data.items.0.configuration_context', $snapshot);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson('/api/admin/orders/'.Order::sole()->id)->assertOk()
            ->assertJsonPath('data.items.0.configuration_context', $snapshot);
        $this->assertSame(3, $stock->fresh()->quantity);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('payments', 0);
        Http::assertNothingSent();
        // Captured in Mail::fake(), never handed to an SMTP transport.
        Mail::assertSent(OrderCreatedMail::class, 1);
    }

    public static function profiles(): array
    {
        return [['vehicle-oem-memory-v1', 'BMW'], ['vehicle-oem-reference-v1', 'Toyota']];
    }

    #[DataProvider('yearBoundaries')]
    public function test_exact_technical_year_boundaries_survive_sql(int $oemIndex, int $year): void
    {
        extract(MultimediaFixture::create());
        $payload['items'][0]['configuration_context']['vehicle_multimedia_system_id'] = $oems[$oemIndex]->id;
        $payload['items'][0]['configuration_context']['year'] = $year;
        $this->withHeader('Idempotency-Key', 'boundary')->postJson('/api/orders', $payload)->assertCreated()
            ->assertJsonPath('data.items.0.configuration_context.year', $year);
    }

    public static function yearBoundaries(): array
    {
        return [[0, 2012], [0, 2013], [1, 2014], [1, 2018]];
    }

    public function test_variant_attributes_are_independent_and_existing_order_is_not_rewritten(): void
    {
        extract(MultimediaFixture::create());
        $second = $product->variants()->create(['name' => 'Physical kit B', 'normalized_name' => 'physical kit b',
            'sku' => 'ISOLATED-ANDROID-B', 'price' => 1500000, 'is_active' => true, 'is_visible' => true]);
        foreach ($variant->specValues()->with('field')->get() as $spec) {
            $second->specValues()->create(['product_category_field_id' => $spec->product_category_field_id,
                'value_number' => $spec->field->field_key === 'ram_gb' ? 8 : 256]);
        }
        $created = $this->withHeader('Idempotency-Key', 'historical-context')->postJson('/api/orders', $payload)->assertCreated();
        $snapshot = DB::table('order_items')->sole()->configuration_context;
        $variant->specValues()->whereHas('field', fn ($field) => $field->where('field_key', 'ram_gb'))->update(['value_number' => 16]);
        $this->assertEquals(['ram_gb' => 8, 'almacenamiento_gb' => 256], $second->fresh()->load('specValues.field')->specs);
        $this->getJson('/api/checkout/orders/'.$created->json('public_token'))->assertOk()
            ->assertJsonPath('data.items.0.configuration_context.ram_gb', 8);
        try {
            app(ProductConfigurationValidator::class)->revalidateOrder(Order::sole());
            $this->fail('Stale selection must fail before reservation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items', $exception->errors());
        }
        $this->assertSame($snapshot, DB::table('order_items')->sole()->configuration_context);
        $this->assertSame(3, $stock->fresh()->quantity);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_aggregated_stock_does_not_invent_a_branch_that_can_fulfil(): void
    {
        extract(MultimediaFixture::create());
        $stock->update(['quantity' => 2]);
        $other = Branch::create(['name' => 'Second branch', 'code' => 'SECOND', 'slug' => 'second', 'city' => 'Bogotá', 'is_active' => true]);
        InventoryStock::create(['inventory_item_id' => $item->id, 'branch_id' => $other->id, 'quantity' => 2]);
        $payload['items'][0]['quantity'] = 3;
        $this->withHeader('Idempotency-Key', 'not-aggregate-stock')->postJson('/api/orders', $payload)->assertUnprocessable()
            ->assertJsonValidationErrors('items')->assertJsonMissingPath('errors.idempotency_key');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_reference_profile_rejects_invented_memory_through_real_http(): void
    {
        extract(MultimediaFixture::create('vehicle-oem-reference-v1', 'Toyota'));
        $payload['items'][0]['configuration_context']['ram_gb'] = 8;
        $this->withHeader('Idempotency-Key', 'no-fake-memory')->postJson('/api/orders', $payload)->assertUnprocessable()
            ->assertJsonValidationErrors('items')->assertJsonMissingPath('errors.idempotency_key');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_new_variant_never_inherits_production_approval_from_its_parent(): void
    {
        extract(MultimediaFixture::create());
        $newVariant = $product->variants()->create(['name' => 'New kit', 'normalized_name' => 'new kit',
            'sku' => 'NEW-UNREVIEWED-KIT', 'price' => 1, 'is_active' => true, 'is_visible' => true]);
        $this->app->instance('env', 'production');
        config(['product_configurator.production_approved' => true,
            'product_configurator.products.'.$product->id.'.reviewed' => true,
            'product_configurator.products.'.$product->id.'.approved_variant_ids' => [$variant->id],
            'product_configurator.catalog' => app(ProductConfiguratorRegistry::class)->catalogIdentity($product)]);
        try {
            $this->getJson('/api/products/'.$product->slug)->assertOk()
                ->assertJsonPath('data.configuration.purchasable_variant_ids', [$variant->id]);
            $payload['items'][0]['product_variant_id'] = $newVariant->id;
            $this->withHeader('Idempotency-Key', 'new-not-approved')->postJson('/api/orders', $payload)->assertUnprocessable()
                ->assertJsonValidationErrors('items')->assertJsonMissingPath('errors.idempotency_key');
            $this->assertFalse(app(ProductConfiguratorRegistry::class)->variantPurchaseApproved($product, $newVariant));
            $this->assertDatabaseCount('orders', 0);
            $payload['items'][0]['product_variant_id'] = $variant->id;
            $this->withHeader('Idempotency-Key', 'approved-isolated-only')->postJson('/api/orders', $payload)->assertCreated();
            config(['product_configurator.products.'.$product->id.'.approved_variant_ids' => []]);
            $this->withHeader('Idempotency-Key', 'missing-approval')->postJson('/api/orders', $payload)->assertUnprocessable();
            $this->assertDatabaseCount('orders', 1);
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    #[DataProvider('invalidSelections')]
    public function test_direct_http_rejects_invalid_context_without_writes(string $case): void
    {
        extract(MultimediaFixture::create());
        $line = &$payload['items'][0];
        switch ($case) {
            case 'missing': unset($line['configuration_context']);
                break;
            case 'no_variant': unset($line['product_variant_id']);
                break;
            case 'unknown_variant': $line['product_variant_id'] += 999;
                break;
            case 'foreign_variant':
                $other = Product::create(['name' => 'Other', 'slug' => 'other', 'price' => 1]);
                $line['product_variant_id'] = $other->variants()->create(['name' => 'Other', 'normalized_name' => 'other', 'price' => 1])->id;
                break;
            case 'inactive': $variant->update(['is_active' => false]);
                break;
            case 'hidden': $variant->update(['is_visible' => false]);
                break;
            case 'year_missing': unset($line['configuration_context']['year']);
                break;
            case 'year_before': $line['configuration_context']['year'] = 2011;
                break;
            case 'year_outside_oem': $line['configuration_context']['year'] = 2014;
                break;
            case 'year_after': $line['configuration_context']['year'] = 2019;
                break;
            case 'wrong_oem': $line['configuration_context']['vehicle_multimedia_system_id'] += 999;
                break;
            case 'wrong_generation': $line['configuration_context']['vehicle_version_id'] += 999;
                break;
            case 'cross_row':
                $variant->vehicleCompatibilities()->where('vehicle_multimedia_system_id', $oems[0]->id)->update(['vehicle_version_id' => null]);
                $variant->vehicleCompatibilities()->where('vehicle_multimedia_system_id', $oems[1]->id)->update(['vehicle_multimedia_system_id' => null]);
                break;
            case 'wrong_memory': $line['configuration_context']['ram_gb'] = 4;
                break;
            case 'missing_memory': unset($line['configuration_context']['ram_gb']);
                break;
            case 'price_injection': $line['unit_price'] = 1;
                break;
            case 'client_activation': $line['configuration_context']['purchase_enabled'] = true;
                break;
            case 'disabled': config(['product_configurator.purchase_enabled' => false]);
                break;
            case 'missing_enrollment': config(['product_configurator.products' => []]);
                unset($line['configuration_context']);
                break;
            case 'wrong_catalog': config(['product_configurator.catalog.database' => 'not-this-catalog']);
                break;
            case 'no_stock': $stock->update(['quantity' => 0]);
                break;
            case 'insufficient_stock': $line['quantity'] = 4;
                break;
        }
        $this->withHeader('Idempotency-Key', 'invalid-'.$case)->postJson('/api/orders', $payload)->assertUnprocessable()
            ->assertJsonMissingPath('errors.idempotency_key')->assertJsonMissingPath('errors.customer_phone');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public static function invalidSelections(): array
    {
        $cases = ['missing', 'no_variant', 'unknown_variant', 'foreign_variant', 'inactive', 'hidden', 'year_missing',
            'year_before', 'year_outside_oem', 'year_after', 'wrong_oem', 'wrong_generation', 'cross_row', 'wrong_memory', 'missing_memory',
            'price_injection', 'client_activation', 'disabled', 'missing_enrollment', 'wrong_catalog', 'no_stock', 'insufficient_stock'];

        return array_combine($cases, array_map(fn ($case) => [$case], $cases));
    }

    public function test_same_physical_sku_two_oem_contexts_and_idempotency_use_real_sql(): void
    {
        extract(MultimediaFixture::create());
        $payload['items'][] = $payload['items'][0];
        $payload['items'][1]['configuration_context']['vehicle_multimedia_system_id'] = $oems[1]->id;
        $payload['items'][1]['configuration_context']['year'] = 2016;
        $created = $this->withHeader('Idempotency-Key', 'shared')->postJson('/api/orders', $payload)->assertCreated();
        $this->withHeader('Idempotency-Key', 'shared')->postJson('/api/orders', $payload)->assertOk()
            ->assertJsonPath('data.id', $created->json('data.id'));
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 2);
        $this->assertSame(1, Order::sole()->items()->distinct()->count('product_variant_id'));
        $this->assertSame(1, InventoryItem::where('product_variant_id', $variant->id)->count());
        $payload['items'][0]['configuration_context']['year'] = 2012;
        $this->withHeader('Idempotency-Key', 'shared')->postJson('/api/orders', $payload)->assertUnprocessable()
            ->assertJsonPath('code', 'IDEMPOTENCY_CONFLICT');
        $payload['items'][0]['quantity'] = $payload['items'][1]['quantity'] = 2;
        $this->withHeader('Idempotency-Key', 'short')->postJson('/api/orders', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(3, $stock->fresh()->quantity);
    }

    public function test_defaults_and_production_flags_cannot_authorize_an_unreviewed_product(): void
    {
        $this->assertFalse(config('product_configurator.preview_enabled'));
        $this->assertFalse(config('product_configurator.purchase_enabled'));
        $this->assertFalse(config('product_configurator.production_approved'));
        $this->assertSame([], config('product_configurator.products'));
        extract(MultimediaFixture::create());
        $this->app->instance('env', 'production');
        config(['product_configurator.production_approved' => true,
            'product_configurator.catalog' => app(ProductConfiguratorRegistry::class)->catalogIdentity($product)]);
        try {
            $this->withHeader('Idempotency-Key', 'production-blocked')->postJson('/api/orders', $payload)->assertUnprocessable()
                ->assertJsonMissingPath('errors.idempotency_key');
            $this->assertFalse(app(ProductConfiguratorRegistry::class)->registration($product)['purchase_enabled']);
            $this->assertDatabaseCount('orders', 0);
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    public function test_simple_traditional_product_without_variants_still_uses_server_price(): void
    {
        $product = Product::create(['name' => 'Traditional', 'slug' => 'traditional', 'price' => 123000,
            'is_active' => true, 'is_visible' => true]);
        $branch = Branch::create(['name' => 'Traditional', 'slug' => 'traditional', 'code' => 'TRAD', 'city' => 'Barranquilla', 'is_active' => true]);
        $item = InventoryItem::create(['product_id' => $product->id]);
        InventoryStock::create(['branch_id' => $branch->id, 'inventory_item_id' => $item->id, 'quantity' => 2]);
        $this->withHeader('Idempotency-Key', 'traditional')->postJson('/api/orders', ['customer_name' => 'Isolated', 'customer_email' => 'isolated@example.test', 'customer_phone' => '3000000000',
            'items' => [['product_id' => $product->id, 'quantity' => 1]]])->assertCreated()
            ->assertJsonPath('data.items.0.unit_price', 123000)
            ->assertJsonPath('data.items.0.configuration_context', null);
    }
}
