<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Models\VehicleBrand;
use App\Models\VehicleModel;
use App\Models\VehicleMultimediaSystem;
use App\Models\VehicleVersion;
use App\Services\EcommerceStockReservationExpiryService;
use App\Services\ProductConfiguratorRegistry;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Additional audit coverage; keeps Stage E's existing QA records, with no migrate:fresh. */
class ProductConfiguratorStageFAuditTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('upgrade_test', DB::selectOne('SELECT DATABASE() AS db')->db);
        $this->assertTrue(Schema::hasColumn('order_items', 'configuration_context'));
        Mail::fake();
        Queue::fake();
        Http::preventStrayRequests();
        Storage::persistentFake('public');
        $this->withoutMiddleware(ThrottleRequests::class);
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));
        config(['services.wompi' => [
            'base_url' => 'https://sandbox.wompi.co/v1', 'checkout_url' => 'https://checkout.wompi.co/p/',
            'public_key' => 'pub_test_stage_f', 'integrity_secret' => 'test_integrity_stage_f',
            'redirect_url' => 'https://qa.example.test/checkout/payment/return', 'reservation_minutes' => 30,
        ]]);
    }

    private function fixture(): array
    {
        $tag = 'stage-f-'.Str::uuid();
        $category = ProductCategory::create(['name' => $tag, 'slug' => $tag, 'is_active' => true, 'requires_oem_compatibility' => true]);
        foreach (['ram_gb', 'almacenamiento_gb'] as $key) {
            $category->fields()->create(['name' => $key, 'field_key' => $key, 'type' => 'number', 'scope' => 'variant', 'filter_unit' => 'GB', 'is_active' => true]);
        }
        $brand = VehicleBrand::create(['name' => $tag, 'slug' => $tag, 'is_active' => true]);
        $model = VehicleModel::create(['name' => 'Audit model', 'slug' => 'audit-model', 'vehicle_brand_id' => $brand->id, 'is_active' => true]);
        $version = VehicleVersion::create(['name' => 'Audit generation', 'vehicle_model_id' => $model->id, 'year_from' => 2012, 'year_to' => 2018, 'is_active' => true]);
        $rows = [];
        foreach (['CIC', 'NBT'] as $name) {
            $system = VehicleMultimediaSystem::create(['name' => $name, 'slug' => strtolower($name), 'vehicle_brand_id' => $brand->id, 'is_active' => true]);
            $version->multimediaSystems()->attach($system->id);
            $rows[] = ['vehicle_brand_id' => $brand->id, 'vehicle_model_id' => $model->id, 'vehicle_version_id' => $version->id, 'vehicle_multimedia_system_id' => $system->id];
        }
        $variantData = [];
        foreach ([128, 256] as $index => $storage) {
            $variantData[] = ['name' => 'Audit kit '.$storage, 'sku' => $tag.'-'.$storage, 'price' => 1200000 + $index * 300000,
                'is_active' => true, 'is_visible' => true, 'is_default' => $index === 0, 'compatibility_type' => 'vehicle_specific',
                'specs' => ['ram_gb' => 8, 'almacenamiento_gb' => $storage], 'vehicle_compatibilities' => $index === 0 ? $rows : [$rows[1]]];
        }
        $response = $this->post('/api/admin/products', ['name' => $tag, 'category_id' => $category->id,
            'compatibility_type' => 'universal', 'is_visible' => true, 'variants' => $variantData,
            'images' => [UploadedFile::fake()->image('front.png'), UploadedFile::fake()->image('rear.png')],
            'gallery' => json_encode([['upload_index' => 0], ['upload_index' => 1]]), 'primary_image_index' => 0,
        ], ['Accept' => 'application/json'])->assertCreated();
        $product = Product::findOrFail($response->json('data.id'));
        $variants = $product->variants()->orderBy('id')->get();
        foreach ($variantData as $index => &$data) {
            $data['id'] = $variants[$index]->id;
        }
        unset($data);
        $branch = Branch::create(['name' => $tag, 'code' => substr((string) Str::uuid(), 0, 10), 'slug' => $tag, 'city' => 'Barranquilla', 'is_active' => true]);
        $physical = InventoryItem::firstOrCreate(['product_id' => null, 'product_variant_id' => $variants[0]->id]);
        $stock = InventoryStock::create(['branch_id' => $branch->id, 'inventory_item_id' => $physical->id, 'quantity' => 5]);
        config(['product_configurator.catalog' => app(ProductConfiguratorRegistry::class)->catalogIdentity($product),
            'product_configurator.products' => [$product->id => ['profile_id' => 'vehicle-oem-memory-v1', 'profile_version' => 1]],
            'product_configurator.preview_enabled' => true, 'product_configurator.purchase_enabled' => true]);
        $items = [];
        foreach ($rows as $index => $row) {
            $items[] = ['product_id' => $product->id, 'product_variant_id' => $variants[0]->id, 'quantity' => $index === 0 ? 2 : 1,
                'configuration_context' => ['schema_version' => 1, 'profile_id' => 'vehicle-oem-memory-v1', 'profile_version' => 1,
                    ...$row, 'year' => 2015, 'ram_gb' => 8, 'almacenamiento_gb' => 128]];
        }
        $payload = ['customer_name' => 'Audit isolated', 'customer_email' => $tag.'@example.test', 'customer_phone' => '3000000000', 'items' => $items];

        return compact('tag', 'product', 'variants', 'variantData', 'branch', 'physical', 'stock', 'payload');
    }

    private function init(string $token, string $key): TestResponse
    {
        return $this->call('POST', '/api/checkout/orders/'.$token.'/payments/wompi', [], [], [], [
            'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => $key,
        ], '{}');
    }

    public function test_crm_rename_gallery_edit_and_variant_change_do_not_rewrite_an_existing_order(): void
    {
        extract($this->fixture());
        $beforeImages = $product->images()->orderBy('sort_order')->get();
        $variantImage = $product->main_image;
        $variants[0]->update(['main_image' => $variantImage]);
        $first = $this->withHeader('Idempotency-Key', $tag)->postJson('/api/orders', $payload)->assertCreated();
        $order = Order::findOrFail($first->json('data.id'));
        $token = $first->json('public_token');
        $snapshot = $order->items()->orderBy('id')->get()->toArray();
        $oldSlug = $product->slug;
        $this->postJson('/api/admin/products/'.$product->id, ['name' => 'Renamed '.$tag])->assertOk();
        $product->refresh();
        $this->assertNotSame($oldSlug, $product->slug);
        $this->getJson('/api/products/'.$oldSlug)->assertNotFound();
        $this->getJson('/api/products/'.$product->slug)->assertOk()->assertJsonPath('data.configuration.purchase_enabled', true)
            ->assertJsonCount(2, 'data.variants')->assertJsonMissingPath('data.cost_price');
        $this->assertSame($beforeImages->pluck('path')->all(), $product->images()->orderBy('sort_order')->pluck('path')->all());
        $this->postJson('/api/admin/products/'.$product->id, ['gallery' => [['id' => $beforeImages[1]->id], ['id' => $beforeImages[0]->id]], 'primary_image_index' => 0])
            ->assertOk()->assertJsonPath('data.images.0.is_primary', true);
        $this->assertSame($variantImage, $variants[0]->fresh()->main_image);
        $this->assertSame($variantData[0]['sku'], $variants[0]->fresh()->sku);
        $this->assertSame(1200000, $variants[0]->fresh()->price);
        $changed = $variantData;
        $changed[0]['specs']['ram_gb'] = 16;
        $this->postJson('/api/admin/products/'.$product->id, ['variants' => $changed])->assertOk();
        $this->assertEquals(16, $variants[0]->fresh()->load('specValues.field')->specs['ram_gb']);
        $this->assertEquals(['ram_gb' => 8, 'almacenamiento_gb' => 256], $variants[1]->fresh()->load('specValues.field')->specs);
        $this->postJson('/api/checkout/orders/'.$token.'/pickup', ['branch_slug' => $branch->slug])->assertOk();
        $this->init($token, $tag.'-blocked')->assertStatus(409)->assertJsonPath('code', 'ORDER_NOT_READY_FOR_PAYMENT');
        $this->assertSame(5, $stock->fresh()->quantity);
        $this->assertFalse($order->payments()->exists());
        $this->assertSame($snapshot, $order->items()->orderBy('id')->get()->toArray());
        $this->postJson('/api/admin/products/'.$product->id, ['variants' => $variantData])->assertOk();
        $this->postJson('/api/admin/products/'.$product->id, ['is_visible' => false])->assertOk();
        $this->getJson('/api/products/'.$product->slug)->assertNotFound();
        $this->withHeader('Idempotency-Key', $tag.'-hidden')->postJson('/api/orders', $payload)->assertUnprocessable();
        $this->postJson('/api/admin/products/'.$product->id, ['is_visible' => true])->assertOk();
        $this->init($token, $tag.'-restored')->assertCreated();
        $this->assertSame(2, $stock->fresh()->quantity);
        $this->assertSame($snapshot, $order->items()->orderBy('id')->get()->toArray());
        Http::assertNothingSent();
    }

    public function test_multi_oem_quantities_require_one_branch_and_expire_without_duplicate_release(): void
    {
        extract($this->fixture());
        $other = Branch::create(['name' => 'Other '.$tag, 'code' => substr((string) Str::uuid(), 0, 10), 'slug' => 'other-'.$tag, 'city' => 'Bogotá', 'is_active' => true]);
        $otherStock = InventoryStock::create(['branch_id' => $other->id, 'inventory_item_id' => $physical->id, 'quantity' => 2]);
        $stock->update(['quantity' => 2]);
        $this->withHeader('Idempotency-Key', $tag.'-split')->postJson('/api/orders', $payload)->assertUnprocessable();
        $this->assertSame(0, Order::where('customer_email', $payload['customer_email'])->count());
        $stock->update(['quantity' => 5]);
        $first = $this->withHeader('Idempotency-Key', $tag)->postJson('/api/orders', $payload)->assertCreated();
        $order = Order::findOrFail($first->json('data.id'));
        $snapshot = $order->items()->orderBy('id')->get()->toArray();
        $this->withHeader('Idempotency-Key', $tag)->postJson('/api/orders', $payload)->assertOk()->assertJsonPath('data.id', $order->id);
        $token = $first->json('public_token');
        $this->postJson('/api/checkout/orders/'.$token.'/pickup', ['branch_slug' => $branch->slug])->assertOk();
        $this->init($token, $tag.'-payment')->assertCreated();
        $this->init($token, $tag.'-payment')->assertOk();
        $this->assertSame(2, $stock->fresh()->quantity);
        $this->assertSame(2, $otherStock->fresh()->quantity);
        $this->assertSame(1, InventoryItem::where('product_variant_id', $variants[0]->id)->count());
        $order->refresh()->update(['stock_reservation_expires_at' => now()->subSecond()]);
        $expiry = app(EcommerceStockReservationExpiryService::class);
        $this->assertSame($expiry::OUTCOME_EXPIRED, $expiry->expire($order->id));
        $this->assertSame($expiry::OUTCOME_SKIPPED, $expiry->expire($order->id));
        $this->assertSame(5, $stock->fresh()->quantity);
        $this->assertSame(2, $otherStock->fresh()->quantity);
        $reversals = InventoryMovement::where('reference_type', Order::class)->where('reference_id', $order->id)->where('type', InventoryMovement::TYPE_SALE_REVERSAL)->get();
        $this->assertCount(2, $reversals);
        $this->assertSame(3, $reversals->sum('quantity_delta'));
        $this->assertSame($snapshot, $order->items()->orderBy('id')->get()->toArray());
        Http::assertNothingSent();
    }
}
