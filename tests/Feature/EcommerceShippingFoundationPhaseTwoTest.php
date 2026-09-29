<?php

namespace Tests\Feature;

use App\Contracts\ShippingQuoteProvider;
use App\Exceptions\EcommerceShippingException;
use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\OrderCharge;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Providers\AppServiceProvider;
use App\Services\EcommerceFulfillmentBranchResolver;
use App\Services\LogisticsSnapshotResolver;
use App\Services\OrderTotalService;
use App\Services\ShippingPackageBuilder;
use App\Services\ShippingQuoteService;
use App\Support\Shipping\ShippingAddressData;
use App\Support\Shipping\ShippingOriginData;
use App\Support\Shipping\ShippingQuoteRequest;
use App\Support\Shipping\ShippingQuoteResult;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class EcommerceShippingFoundationPhaseTwoTest extends TestCase
{
    use RefreshDatabase;

    public function createApplication()
    {
        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        if ($connection = getenv('TEST_DB_CONNECTION')) {
            $app['config']->set('database.default', $connection);
            $app['config']->set("database.connections.{$connection}.database", getenv('TEST_DB_DATABASE') ?: 'upgrade_test');
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_one_branch_can_fulfill_the_complete_order(): void
    {
        $baq = $this->branch('BAQ', 20);
        $bog = $this->branch('BOG', 10);
        $product = $this->product();
        $order = $this->order([[$product, null, 2]]);
        $this->stock($baq, $product, 2);
        $this->stock($bog, $product, 1);

        $resolver = app(EcommerceFulfillmentBranchResolver::class);

        $this->assertSame([$baq->id], $resolver->candidates($order)->modelKeys());
        $this->assertTrue($resolver->select($order)->is($baq));
    }

    public function test_priority_then_branch_id_selects_deterministically(): void
    {
        $laterPriority = $this->branch('LATE', 50);
        $preferred = $this->branch('FIRST', 5);
        $samePriorityLaterId = $this->branch('SECOND', 5);
        $product = $this->product();
        $order = $this->order([[$product, null, 1]]);
        foreach ([$laterPriority, $preferred, $samePriorityLaterId] as $branch) {
            $this->stock($branch, $product, 1);
        }

        $candidates = app(EcommerceFulfillmentBranchResolver::class)->candidates($order);

        $this->assertSame([$preferred->id, $samePriorityLaterId->id, $laterPriority->id], $candidates->modelKeys());
        $this->assertTrue($candidates->first()->is($preferred));
    }

    public function test_no_branch_can_fulfill_returns_a_distinguishable_domain_error(): void
    {
        $branch = $this->branch('EMPTY', 1);
        $product = $this->product();
        $order = $this->order([[$product, null, 2]]);
        $this->stock($branch, $product, 1);

        $this->assertShippingError(
            EcommerceShippingException::NO_BRANCH_CAN_FULFILL,
            fn () => app(EcommerceFulfillmentBranchResolver::class)->select($order),
        );
    }

    public function test_products_split_between_branches_do_not_enable_split_fulfillment(): void
    {
        $baq = $this->branch('BAQ', 1);
        $bog = $this->branch('BOG', 2);
        $screen = $this->product(['name' => 'Pantalla']);
        $camera = $this->product(['name' => 'Cámara']);
        $order = $this->order([[$screen, null, 1], [$camera, null, 1]]);
        $this->stock($baq, $screen, 1);
        $this->stock($bog, $camera, 1);

        $resolver = app(EcommerceFulfillmentBranchResolver::class);

        $this->assertCount(0, $resolver->candidates($order));
        $this->assertShippingError(EcommerceShippingException::NO_BRANCH_CAN_FULFILL, fn () => $resolver->select($order));
    }

    public function test_variant_logistics_override_non_null_values_and_inherit_null_values(): void
    {
        $product = $this->product([
            'weight_grams' => 2000,
            'length_mm' => 400,
            'width_mm' => 250,
            'height_mm' => 100,
            'country_of_origin' => 'CN',
            'hs_code' => '8528.72',
            'customs_description' => 'Pantalla',
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Reforzada',
            'normalized_name' => 'reforzada',
            'price' => 130000,
            'weight_grams' => 2800,
            'height_mm' => 140,
            'is_active' => true,
            'is_visible' => true,
        ]);
        $order = $this->order([[$product, $variant, 2]]);

        $package = app(LogisticsSnapshotResolver::class)->resolve($order->items->first());

        $this->assertSame(2800, $package->weightGrams);
        $this->assertSame(400, $package->lengthMm);
        $this->assertSame(250, $package->widthMm);
        $this->assertSame(140, $package->heightMm);
        $this->assertSame('CN', $package->countryOfOrigin);
        $this->assertSame(130000, $package->declaredUnitValue);
        $this->assertSame(2, $package->quantity);
    }

    public function test_missing_dimensions_fail_structurally_and_service_lines_create_no_packages(): void
    {
        $product = $this->product(['weight_grams' => null, 'length_mm' => null]);
        $order = $this->order([[$product, null, 1]]);
        $builder = app(ShippingPackageBuilder::class);

        try {
            $builder->build($order, $this->origin(), $this->destination());
            $this->fail('Expected missing logistics error.');
        } catch (EcommerceShippingException $exception) {
            $this->assertSame(EcommerceShippingException::MISSING_LOGISTICS_DATA, $exception->errorCode);
            $this->assertContains('weight_grams', $exception->context['missing_fields']);
            $this->assertContains('length_mm', $exception->context['missing_fields']);
        }

        $serviceOrder = $this->serviceOnlyOrder();
        $branch = $this->branch('SERVICE', 1);
        $this->assertSame([$branch->id], app(EcommerceFulfillmentBranchResolver::class)->candidates($serviceOrder)->modelKeys());
        $this->assertSame([], $builder->build($serviceOrder, $this->origin(), $this->destination()));
    }

    public function test_fake_provider_receives_normalized_origin_destination_packages_and_currency(): void
    {
        [$order, $branch] = $this->quotableOrder();
        $fake = new FakeShippingQuoteProvider([
            new ShippingQuoteResult(
                provider: 'fake',
                serviceCode: 'STANDARD',
                serviceName: 'Estándar',
                amount: 22000,
                currency: 'COP',
                quoteReference: 'FAKE-1',
                estimatedDaysMin: 2,
                estimatedDaysMax: 4,
                expiresAt: CarbonImmutable::now()->addHour(),
                metadata: ['zone' => 'national', 'api_token' => 'must-not-survive'],
            ),
        ]);

        $quotes = $this->quoteService($fake)->quote($order);

        $this->assertCount(1, $quotes);
        $this->assertSame($branch->id, $quotes[0]->branchId);
        $this->assertSame(22000, $quotes[0]->amount);
        $this->assertArrayNotHasKey('api_token', $quotes[0]->metadata);
        $this->assertSame('COP', $fake->lastRequest->currency);
        $this->assertSame('CO', $fake->lastRequest->origin->countryCode);
        $this->assertSame('CO', $fake->lastRequest->destination->countryCode);
        $this->assertCount(1, $fake->lastRequest->packages);
    }

    public function test_applying_quote_is_idempotent_and_recalculates_total_from_backend_result(): void
    {
        [$order, $branch] = $this->quotableOrder();
        $fake = new FakeShippingQuoteProvider([$this->quoteResult(25000)]);
        $service = $this->quoteService($fake);
        $quote = $service->quote($order)[0];

        $first = $service->apply($order, $quote);
        $second = $service->apply($first, $quote);

        $this->assertSame($branch->id, $second->branch_id);
        $this->assertSame(Order::FULFILLMENT_SHIPPING, $second->fulfillment_type);
        $this->assertSame(25000, $second->charges_total);
        $this->assertSame(125000, $second->total);
        $this->assertSame(1, $second->charges()->where('type', OrderCharge::TYPE_SHIPPING)->count());
        $charge = $second->charges()->where('type', OrderCharge::TYPE_SHIPPING)->firstOrFail();
        $this->assertSame('FAKE-QUOTE', $charge->metadata['quote_reference']);
        $this->assertSame(25000, $charge->amount);
    }

    public function test_expired_quote_is_rejected_without_creating_a_charge(): void
    {
        [$order, $branch] = $this->quotableOrder();
        $quote = $this->quoteResult(25000, $branch->id, CarbonImmutable::now()->subMinute());
        $service = $this->quoteService(new FakeShippingQuoteProvider([]));

        $this->assertShippingError(EcommerceShippingException::QUOTE_EXPIRED, fn () => $service->apply($order, $quote));
        $this->assertDatabaseCount('order_charges', 0);
    }

    public function test_public_checkout_amount_cannot_participate_in_shipping_total(): void
    {
        $product = $this->product(['price' => 90000]);
        $payload = [
            'customer_name' => 'Cliente',
            'customer_email' => 'shipping@example.com',
            'customer_phone' => '+573001234567',
            'shipping_total' => 1,
            'charges' => [['type' => 'shipping', 'amount' => 1]],
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ];

        $this->withHeader('Idempotency-Key', __METHOD__)->postJson('/api/orders', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['shipping_total', 'charges']);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_pickup_requires_a_fulfilling_branch_removes_shipping_charge_and_needs_no_address(): void
    {
        $branch = $this->branch('PICKUP', 1);
        $product = $this->product();
        $order = $this->order([[$product, null, 1]]);
        $this->stock($branch, $product, 1);
        $order->charges()->create([
            'type' => OrderCharge::TYPE_SHIPPING,
            'label' => 'Envío anterior',
            'amount' => 30000,
        ]);
        app(OrderTotalService::class)->recalculate($order);

        $updated = $this->quoteService(new FakeShippingQuoteProvider([]))->applyPickup($order, $branch);

        $this->assertSame(Order::FULFILLMENT_PICKUP, $updated->fulfillment_type);
        $this->assertSame($branch->id, $updated->branch_id);
        $this->assertSame(0, $updated->charges_total);
        $this->assertSame(100000, $updated->total);
        $this->assertDatabaseMissing('order_charges', ['order_id' => $order->id, 'type' => OrderCharge::TYPE_SHIPPING]);
    }

    public function test_locked_revalidation_detects_stale_authoritative_branch_stock(): void
    {
        $branch = $this->branch('LOCK', 1);
        $product = $this->product();
        $order = $this->order([[$product, null, 2]]);
        $stock = $this->stock($branch, $product, 2);
        $resolver = app(EcommerceFulfillmentBranchResolver::class);
        $this->assertTrue($resolver->select($order)->is($branch));
        $stock->update(['quantity' => 1]);

        $this->assertShippingError(
            EcommerceShippingException::STALE_STOCK,
            fn () => DB::transaction(fn () => $resolver->revalidateUnderLock($order, $branch)),
        );
    }

    public function test_quote_errors_cover_missing_address_and_empty_provider_results(): void
    {
        $branch = $this->branch('ERRORS', 1);
        $product = $this->product();
        $order = $this->order([[$product, null, 1]]);
        $this->stock($branch, $product, 1);
        $service = $this->quoteService(new FakeShippingQuoteProvider([]));

        $this->assertShippingError(EcommerceShippingException::MISSING_SHIPPING_ADDRESS, fn () => $service->quote($order));
        $this->address($order);
        $this->assertShippingError(EcommerceShippingException::NO_QUOTES_AVAILABLE, fn () => $service->quote($order->fresh()));
    }

    public function test_shipping_quote_rate_limit_blocks_provider_calls_after_the_limit(): void
    {
        [$order] = $this->quotableOrder();
        $token = bin2hex(random_bytes(32));
        $order->update([
            'origin' => Order::ORIGIN_ECOMMERCE,
            'public_token_hash' => hash('sha256', $token),
        ]);
        $provider = new FakeShippingQuoteProvider([$this->quoteResult(25000)]);
        $this->app->instance(ShippingQuoteProvider::class, $provider);
        $identity = hash('sha256', $token).'|127.0.0.1';
        RateLimiter::clear(md5('checkout-shipping'.$identity));

        for ($attempt = 1; $attempt <= AppServiceProvider::CHECKOUT_SHIPPING_REQUESTS_PER_MINUTE; $attempt++) {
            $this->postJson('/api/checkout/orders/'.$token.'/shipping-quotes')
                ->assertOk()
                ->assertJsonCount(1, 'data.quotes');
        }

        $this->postJson('/api/checkout/orders/'.$token.'/shipping-quotes')
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('message', 'Too Many Attempts.');

        $this->assertSame(AppServiceProvider::CHECKOUT_SHIPPING_REQUESTS_PER_MINUTE, $provider->calls);
        $this->assertDatabaseCount('order_charges', 0);
    }

    private function branch(string $code, int $priority): Branch
    {
        return Branch::create([
            'code' => $code,
            'slug' => strtolower($code).'-'.uniqid(),
            'name' => 'Sede '.$code,
            'city' => 'Barranquilla',
            'is_active' => true,
            'ecommerce_priority' => $priority,
            'country_code' => 'CO',
            'state' => 'Atlántico',
            'postal_code' => '080001',
            'address_line1' => 'Origen de prueba '.$code,
        ]);
    }

    private function product(array $attributes = []): Product
    {
        $name = $attributes['name'] ?? 'Producto '.uniqid();

        return Product::create([
            'name' => $name,
            'slug' => 'producto-'.uniqid(),
            'sku' => 'SKU-'.uniqid(),
            'price' => 100000,
            'requires_shipping' => true,
            'weight_grams' => 1500,
            'length_mm' => 300,
            'width_mm' => 200,
            'height_mm' => 100,
            'country_of_origin' => 'CO',
            'hs_code' => '8708.99',
            'customs_description' => 'Accesorio automotriz',
            'is_active' => true,
            'is_visible' => true,
            ...$attributes,
        ]);
    }

    /** @param list<array{0: Product, 1: ?ProductVariant, 2: int}> $lines */
    private function order(array $lines): Order
    {
        $subtotal = collect($lines)->sum(fn (array $line) => (int) ($line[1]?->price ?? $line[0]->price) * $line[2]);
        $order = Order::create([
            'order_number' => 'SHIP2-'.uniqid(),
            'customer_name' => 'Cliente Shipping',
            'subtotal' => $subtotal,
            'discount_total' => 0,
            'total' => $subtotal,
            'status' => Order::STATUS_PENDING,
            'payment_status' => Order::PAYMENT_UNPAID,
        ]);

        foreach ($lines as [$product, $variant, $quantity]) {
            $unitPrice = (int) ($variant?->price ?? $product->price);
            $order->items()->create([
                'item_type' => OrderItem::ITEM_TYPE_PRODUCT,
                'product_id' => $product->id,
                'product_variant_id' => $variant?->id,
                'product_name' => $product->name,
                'product_sku' => $product->sku,
                'variant_name' => $variant?->name,
                'variant_sku' => $variant?->sku,
                'unit_price' => $unitPrice,
                'quantity' => $quantity,
                'subtotal' => $unitPrice * $quantity,
                'discount_amount' => 0,
                'total' => $unitPrice * $quantity,
            ]);
        }

        return $order->load('items');
    }

    private function serviceOnlyOrder(): Order
    {
        $order = Order::create([
            'order_number' => 'SERVICE-'.uniqid(),
            'customer_name' => 'Cliente Service',
            'subtotal' => 50000,
            'discount_total' => 0,
            'total' => 50000,
            'status' => Order::STATUS_PENDING,
            'payment_status' => Order::PAYMENT_UNPAID,
        ]);
        $order->items()->create([
            'item_type' => OrderItem::ITEM_TYPE_SERVICE,
            'service_name' => 'Instalación',
            'unit_price' => 50000,
            'quantity' => 1,
            'subtotal' => 50000,
            'discount_amount' => 0,
            'total' => 50000,
        ]);

        return $order->load('items');
    }

    private function stock(Branch $branch, Product $product, int $quantity, ?ProductVariant $variant = null): InventoryStock
    {
        $item = InventoryItem::firstOrCreate($variant
            ? ['product_id' => null, 'product_variant_id' => $variant->id]
            : ['product_id' => $product->id, 'product_variant_id' => null]);

        return InventoryStock::create([
            'branch_id' => $branch->id,
            'inventory_item_id' => $item->id,
            'quantity' => $quantity,
            'minimum_quantity' => 0,
        ]);
    }

    private function address(Order $order): void
    {
        $order->shippingAddress()->create([
            'recipient_name' => 'Cliente Shipping',
            'recipient_phone' => '+573001234567',
            'country_code' => 'CO',
            'state' => 'Atlántico',
            'city' => 'Barranquilla',
            'postal_code' => '080001',
            'address_line1' => 'Destino de prueba',
        ]);
    }

    /** @return array{Order, Branch} */
    private function quotableOrder(): array
    {
        $branch = $this->branch('QUOTE', 1);
        $product = $this->product();
        $order = $this->order([[$product, null, 1]]);
        $this->stock($branch, $product, 3);
        $this->address($order);

        return [$order, $branch];
    }

    private function quoteResult(int $amount, ?int $branchId = null, ?CarbonImmutable $expiresAt = null): ShippingQuoteResult
    {
        return new ShippingQuoteResult(
            provider: 'fake',
            serviceCode: 'STANDARD',
            serviceName: 'Estándar',
            amount: $amount,
            currency: 'COP',
            quoteReference: 'FAKE-QUOTE',
            estimatedDaysMin: 2,
            estimatedDaysMax: 4,
            expiresAt: $expiresAt ?? CarbonImmutable::now()->addHour(),
            metadata: ['zone' => 'national'],
            branchId: $branchId,
        );
    }

    private function quoteService(FakeShippingQuoteProvider $provider): ShippingQuoteService
    {
        $this->app->instance(ShippingQuoteProvider::class, $provider);

        return $this->app->make(ShippingQuoteService::class);
    }

    private function origin(): ShippingOriginData
    {
        return new ShippingOriginData(1, 'TEST', 'CO', 'Atlántico', 'Barranquilla', '080001', 'Origen');
    }

    private function destination(): ShippingAddressData
    {
        return new ShippingAddressData('Cliente', '+573001234567', 'CO', 'Atlántico', 'Barranquilla', '080001', 'Destino');
    }

    private function assertShippingError(string $code, callable $callback): void
    {
        try {
            $callback();
            $this->fail("Expected shipping error {$code}.");
        } catch (EcommerceShippingException $exception) {
            $this->assertSame($code, $exception->errorCode);
        }
    }
}

class FakeShippingQuoteProvider implements ShippingQuoteProvider
{
    public ?ShippingQuoteRequest $lastRequest = null;

    public int $calls = 0;

    /** @param list<ShippingQuoteResult> $quotes */
    public function __construct(
        private readonly array $quotes,
    ) {}

    public function quote(ShippingQuoteRequest $request): array
    {
        $this->calls++;
        $this->lastRequest = $request;

        return $this->quotes;
    }
}
