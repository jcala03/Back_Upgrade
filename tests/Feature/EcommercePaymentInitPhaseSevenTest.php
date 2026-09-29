<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\WebhookEvent;
use App\Providers\AppServiceProvider;
use App\Services\PublicCheckoutService;
use App\Services\WompiCheckoutService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class EcommercePaymentInitPhaseSevenTest extends TestCase
{
    use RefreshDatabase;

    public function createApplication()
    {
        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        if ($app->environment() !== 'testing'
            || getenv('TEST_DB_CONNECTION') !== 'mysql'
            || getenv('TEST_DB_DATABASE') !== 'upgrade_test'
            || config('database.default') !== 'mysql'
            || config('database.connections.mysql.database') !== 'upgrade_test'
            || DB::selectOne('SELECT DATABASE() AS name')->name !== 'upgrade_test') {
            throw new RuntimeException('Payment tests require the effective upgrade_test MySQL database.');
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Http::preventStrayRequests();
        Http::fake();
        $this->travelTo(now()->setTimezone('UTC')->setDate(2026, 9, 20)->setTime(15, 0));
        config(['services.wompi' => [
            'base_url' => 'https://sandbox.wompi.co/v1',
            'checkout_url' => 'https://checkout.wompi.co/p/',
            'public_key' => 'pub_test_fixture',
            'private_key' => 'prv_test_private_fixture',
            'integrity_secret' => 'test_integrity_fixture',
            'events_secret' => 'test_events_fixture',
            'redirect_url' => 'https://shop.example.test/checkout/payment/return',
            'reservation_minutes' => 30,
        ]]);
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        $this->travelBack();
        parent::tearDown();
    }

    public function test_first_init_reserves_stock_and_returns_only_signed_public_parameters(): void
    {
        [$order, $token, $stock] = $this->fixture();
        $response = $this->init($token)->assertCreated()->assertJsonPath('data.amount_in_cents', 24000000)
            ->assertJsonPath('data.currency', 'COP')->assertHeader('Cache-Control', 'no-store, private');
        $order->refresh();
        $payment = Payment::sole();
        $this->assertSame('confirmed', $order->status);
        $this->assertSame('unpaid', $order->payment_status);
        $this->assertSame(3, $stock->fresh()->quantity);
        $this->assertNotNull($order->stock_committed_at);
        $this->assertNull($order->stock_reverted_at);
        $this->assertNull($order->completed_at);
        $this->assertTrue($order->stock_reservation_expires_at->equalTo(now()->addMinutes(30)));
        $this->assertSame($order->total, $payment->amount);
        $this->assertSame('pending', $payment->status);
        $this->assertSame('wompi', $payment->method);
        $this->assertSame('wompi', $payment->provider);
        $this->assertNull($payment->provider_status);
        $this->assertNull($payment->paid_at);
        $this->assertNull($payment->transaction_id);
        $this->assertTrue($payment->expires_at->equalTo($order->stock_reservation_expires_at));
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertDatabaseCount('employee_commissions', 0);
        $this->assertDatabaseCount('webhook_events', 0);
        $this->assertDatabaseHas('order_status_histories', ['order_id' => $order->id, 'from_status' => 'pending', 'to_status' => 'confirmed']);
        $data = $response->json('data');
        $this->assertSame([
            'provider', 'checkout_url', 'public_key', 'amount_in_cents', 'currency',
            'reference', 'integrity_signature', 'redirect_url', 'expiration_time',
        ], array_keys($data));
        $this->assertSame('2026-09-20T15:30:00.000Z', $data['expiration_time']);
        $this->assertSame(hash('sha256', $data['reference'].'24000000COP'.$data['expiration_time'].'test_integrity_fixture'), $data['integrity_signature']);
        $this->assertSame($data, $payment->metadata['checkout']);
        $serialized = json_encode([$data, $payment->metadata]);
        foreach (['test_integrity_fixture', 'prv_test_private_fixture', 'test_events_fixture', $token] as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
        }
        foreach (['payment_provider', 'payment_reference', 'payment_transaction_id'] as $legacy) {
            $this->assertNull($order->{$legacy});
        }
    }

    public function test_replay_is_identical_and_does_not_duplicate_or_extend_reservation(): void
    {
        [$order, $token, $stock] = $this->fixture();
        $first = $this->init($token)->assertCreated()->json();
        $expiry = $order->fresh()->stock_reservation_expires_at;
        $this->travel(10)->minutes();
        $this->assertSame($first, $this->init($token)->assertOk()->json());
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertSame(3, $stock->fresh()->quantity);
        $this->assertTrue($expiry->equalTo($order->fresh()->stock_reservation_expires_at));
    }

    public function test_payment_init_rate_limit_allows_idempotent_retries_and_blocks_extra_effects(): void
    {
        [$order, $token, $stock] = $this->fixture();
        $identity = hash('sha256', $token).'|127.0.0.1';
        RateLimiter::clear(md5('checkout-payment'.$identity));

        for ($attempt = 1; $attempt <= AppServiceProvider::CHECKOUT_PAYMENT_REQUESTS_PER_MINUTE; $attempt++) {
            $response = $this->init($token, __METHOD__);
            $attempt === 1 ? $response->assertCreated() : $response->assertOk();
        }

        $this->init($token, __METHOD__)
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('message', 'Too Many Attempts.');

        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertSame(3, $stock->fresh()->quantity);
        $this->assertTrue($order->fresh()->stock_reservation_expires_at->equalTo(Payment::sole()->expires_at));
    }

    public function test_replay_freezes_public_config_while_new_retry_uses_rotated_config(): void
    {
        [, $token] = $this->fixture();
        $first = $this->init($token)->assertCreated()->json();
        config(['services.wompi.public_key' => 'pub_test_rotated', 'services.wompi.redirect_url' => 'https://other.example.test/return', 'services.wompi.integrity_secret' => 'test_integrity_rotated']);
        $this->assertSame($first, $this->init($token)->assertOk()->json());
        $this->init($token, 'new-key')->assertCreated()->assertJsonPath('data.public_key', 'pub_test_rotated');
    }

    public function test_same_key_different_order_conflicts_without_reserving_second_order(): void
    {
        [, $firstToken] = $this->fixture();
        [$other, $otherToken, $stock] = $this->fixture();
        $this->init($firstToken)->assertCreated();
        $this->init($otherToken)->assertConflict()->assertJsonPath('code', 'IDEMPOTENCY_CONFLICT');
        $this->assertSame('pending', $other->fresh()->status);
        $this->assertSame(5, $stock->fresh()->quantity);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_same_key_different_fingerprint_conflicts(): void
    {
        [, $token] = $this->fixture();
        $this->init($token)->assertCreated();
        Payment::sole()->update(['idempotency_fingerprint' => str_repeat('0', 64)]);
        $this->init($token)->assertConflict()->assertJsonPath('code', 'IDEMPOTENCY_CONFLICT');
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_retry_has_new_reference_same_order_total_and_original_expiry(): void
    {
        [$order, $token, $stock] = $this->fixture();
        $first = $this->init($token)->assertCreated()->json('data');
        Payment::sole()->update(['status' => 'failed', 'provider_status' => 'DECLINED']);
        $this->travel(12)->minutes();
        // Reserved units are already deducted; a retry must not re-check unreserved stock.
        $stock->update(['quantity' => 0]);
        $second = $this->init($token, 'retry-key')->assertCreated()->json('data');
        $this->assertNotSame($first['reference'], $second['reference']);
        $this->assertSame($first['expiration_time'], $second['expiration_time']);
        $this->assertSame($first['amount_in_cents'], $second['amount_in_cents']);
        $this->assertSame(0, $stock->fresh()->quantity);
        $this->assertSame(2, $order->payments()->count());
        $this->assertDatabaseCount('inventory_movements', 1);
    }

    public function test_not_ready_checkout_has_no_side_effects(): void
    {
        [$order, $token, $stock] = $this->fixture(false);
        $this->init($token)->assertConflict()->assertJsonPath('code', 'ORDER_NOT_READY_FOR_PAYMENT');
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertNull($order->stock_reservation_expires_at);
        $this->assertSame(5, $stock->fresh()->quantity);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_stale_stock_is_revalidated_and_confirmation_is_rolled_back(): void
    {
        [$order, $token, $stock] = $this->fixture();
        $stock->update(['quantity' => 1]);
        $this->init($token)->assertConflict()->assertJsonPath('code', 'ORDER_NOT_READY_FOR_PAYMENT');
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertNull($order->stock_committed_at);
        $this->assertNull($order->stock_reservation_expires_at);
        $this->assertSame(1, $stock->fresh()->quantity);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_inactive_branch_is_revalidated(): void
    {
        [$order, $token] = $this->fixture();
        $order->branch->update(['is_active' => false]);
        $this->init($token)->assertConflict()->assertJsonPath('code', 'ORDER_NOT_READY_FOR_PAYMENT');
        $this->assertNull($order->fresh()->stock_committed_at);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_failure_after_stock_commit_rolls_back_entire_init(): void
    {
        [$order, $token, $stock] = $this->fixture();
        $this->mock(WompiCheckoutService::class, function ($mock) {
            $mock->shouldReceive('assertConfigured')->once();
            $mock->shouldReceive('reservationMinutes')->once()->andReturn(30);
            $mock->shouldReceive('payload')->once()->andThrow(new RuntimeException('Simulated payload failure'));
        });
        $this->withoutExceptionHandling();
        try {
            $this->init($token);
            $this->fail('Expected failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated payload failure', $exception->getMessage());
        }
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertNull($order->stock_committed_at);
        $this->assertNull($order->stock_reservation_expires_at);
        $this->assertSame(5, $stock->fresh()->quantity);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    #[DataProvider('unpayableOrders')]
    public function test_unpayable_orders_are_unchanged(array $attributes, string $error): void
    {
        [$order, $token, $stock] = $this->fixture();
        $order->update($attributes);
        $before = $order->fresh()->getAttributes();
        $this->init($token)->assertStatus(in_array($error, ['UNSUPPORTED_CURRENCY', 'INVALID_ORDER_TOTAL']) ? 422 : 409)
            ->assertJsonPath('code', $error);
        $this->assertSame($before, $order->fresh()->getAttributes());
        $this->assertSame(5, $stock->fresh()->quantity);
        $this->assertDatabaseCount('payments', 0);
    }

    public static function unpayableOrders(): array
    {
        return [
            'cancelled' => [['status' => 'cancelled'], 'ORDER_NOT_PAYABLE'],
            'completed' => [['status' => 'completed'], 'ORDER_NOT_PAYABLE'],
            'crm' => [['origin' => 'crm'], 'ORDER_NOT_PAYABLE'],
            'paid' => [['payment_status' => 'paid'], 'PAYMENT_ALREADY_COMPLETED'],
            'partial' => [['payment_status' => 'partial'], 'ORDER_NOT_PAYABLE'],
            'refunded' => [['payment_status' => 'refunded'], 'ORDER_NOT_PAYABLE'],
            'missing reservation' => [['status' => 'confirmed'], 'ORDER_NOT_PAYABLE'],
            'currency' => [['currency' => 'USD'], 'UNSUPPORTED_CURRENCY'],
            'zero total' => [['total' => 0], 'INVALID_ORDER_TOTAL'],
            'overflow total' => [['total' => intdiv(PHP_INT_MAX, 100) + 1], 'INVALID_ORDER_TOTAL'],
        ];
    }

    public function test_expired_reservation_blocks_both_retry_and_replay_without_releasing_stock(): void
    {
        [$order, $token, $stock] = $this->fixture();
        $this->init($token)->assertCreated();
        $this->travel(30)->minutes();
        $this->init($token, 'new-key')->assertConflict()->assertJsonPath('code', 'RESERVATION_EXPIRED');
        $this->init($token)->assertConflict()->assertJsonPath('code', 'RESERVATION_EXPIRED');
        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertSame(3, $stock->fresh()->quantity);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_reconciliation_blocks_retry_and_is_not_exposed_publicly(): void
    {
        [, $token] = $this->fixture();
        $this->init($token)->assertCreated();
        Payment::sole()->update(['reconciliation_required_at' => now(), 'reconciliation_reason' => 'internal-reason']);
        $this->init($token, 'new-key')->assertConflict()->assertJsonPath('code', 'PAYMENT_RECONCILIATION_REQUIRED');
        $this->getJson('/api/checkout/orders/'.$token)->assertOk()->assertJsonPath('data.can_retry_payment', false)
            ->assertJsonMissingPath('data.payments')->assertDontSee('internal-reason');
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_completed_payment_blocks_retry_even_if_order_status_is_stale(): void
    {
        [, $token] = $this->fixture();
        $this->init($token)->assertCreated();
        Payment::sole()->update(['status' => 'completed']);
        $this->init($token, 'new-key')->assertConflict()->assertJsonPath('code', 'PAYMENT_ALREADY_COMPLETED');
        $this->getJson('/api/checkout/orders/'.$token)->assertJsonPath('data.can_retry_payment', false);
    }

    public function test_public_order_exposes_reservation_and_backend_retry_only(): void
    {
        [, $token] = $this->fixture();
        $this->getJson('/api/checkout/orders/'.$token)->assertOk()->assertJsonPath('data.can_retry_payment', false)
            ->assertJsonPath('data.stock_reservation_expires_at', null);
        $this->init($token)->assertCreated();
        $public = $this->getJson('/api/checkout/orders/'.$token)->assertOk()
            ->assertJsonPath('data.can_retry_payment', true)->assertJsonPath('data.payment_status', 'unpaid')
            ->assertJsonPath('data.order_status', 'confirmed')->assertJsonPath('data.ready_for_payment', false);
        $this->assertNotNull($public->json('data.stock_reservation_expires_at'));
        foreach (['payments', 'reference', 'transaction_id', 'metadata', 'branch_id', 'failure_reason', 'integrity_signature', 'public_token_hash'] as $field) {
            $public->assertJsonMissingPath('data.'.$field);
        }
        foreach (['test_integrity_fixture', 'prv_test_private_fixture', 'test_events_fixture'] as $secret) {
            $public->assertDontSee($secret);
        }
        $this->travel(31)->minutes();
        $this->getJson('/api/checkout/orders/'.$token)->assertJsonPath('data.can_retry_payment', false);
    }

    #[DataProvider('forbiddenBodies')]
    public function test_public_request_rejects_amount_currency_and_all_nonempty_payloads(string $body): void
    {
        [, $token] = $this->fixture();
        $this->init($token, 'key', $body)->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public static function forbiddenBodies(): array
    {
        return [['{"amount":1}'], ['{"currency":"USD"}'], ['{"provider":"other"}'], ['{"reference":"forged"}'],
            ['{"shipping":0,"tax":0,"duty":0,"total":1}'], ['{"branch_id":1}'], ['{"status":"completed"}'],
            ['{"unknown":null}'], ['[]'], ['null'], ['invalid']];
    }

    public function test_header_is_required_and_token_is_authority(): void
    {
        [, $token] = $this->fixture();
        $this->init($token, '')->assertUnprocessable();
        $this->init($token, str_repeat('x', 256))->assertUnprocessable();
        $this->init(str_repeat('f', 64))->assertNotFound();
        $this->assertDatabaseCount('payments', 0);
    }

    #[DataProvider('missingConfig')]
    public function test_missing_or_mixed_environment_config_is_controlled(string $field, mixed $value): void
    {
        [$order, $token, $stock] = $this->fixture();
        config(['services.wompi.'.$field => $value]);
        $this->init($token)->assertStatus(503)->assertJsonPath('code', 'WOMPI_NOT_CONFIGURED');
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(5, $stock->fresh()->quantity);
        $this->assertDatabaseCount('payments', 0);
    }

    public static function missingConfig(): array
    {
        return [['public_key', null], ['integrity_secret', null], ['redirect_url', null],
            ['checkout_url', 'https://invalid.example.test'], ['reservation_minutes', 0],
            ['public_key', 'pub_prod_mixed'], ['base_url', 'https://invalid.example.test']];
    }

    public function test_production_config_builds_locally_without_private_key_or_http(): void
    {
        [, $token] = $this->fixture();
        config(['services.wompi.base_url' => 'https://production.wompi.co/v1',
            'services.wompi.public_key' => 'pub_prod_fixture', 'services.wompi.integrity_secret' => 'prod_integrity_fixture',
            'services.wompi.private_key' => null, 'services.wompi.events_secret' => null]);
        $this->init($token)->assertCreated()->assertJsonPath('data.public_key', 'pub_prod_fixture');
    }

    #[DataProvider('uniquePaymentFields')]
    public function test_provider_payment_uniques_are_enforced_by_database(string $field): void
    {
        [$order] = $this->fixture();
        $data = ['order_id' => $order->id, 'amount' => 100, 'method' => 'wompi', 'provider' => 'wompi', $field => 'duplicate'];
        Payment::create($data);
        $this->expectException(UniqueConstraintViolationException::class);
        Payment::create($data);
    }

    public static function uniquePaymentFields(): array
    {
        return [['reference'], ['transaction_id'], ['idempotency_key']];
    }

    public function test_multiple_nulls_preserve_manual_payments_and_currency_defaults(): void
    {
        [$order] = $this->fixture();
        foreach ([null, null, 'wompi', 'wompi'] as $provider) {
            $payment = Payment::create(['order_id' => $order->id, 'amount' => 100, 'method' => 'cash', 'provider' => $provider]);
            $this->assertSame('COP', $payment->fresh()->currency);
        }
        $this->assertDatabaseCount('payments', 4);
    }

    public function test_schema_indexes_and_webhook_event_casts_are_available(): void
    {
        $this->assertTrue(Schema::hasColumn('orders', 'stock_reservation_expires_at'));
        $this->assertTrue(Schema::hasIndex('orders', 'orders_ecommerce_reservation_idx'));
        $this->assertFalse(Schema::hasColumn('orders', 'paid_at'));
        foreach (['payments_provider_reference_unique', 'payments_provider_transaction_unique', 'payments_provider_idempotency_unique',
            'payments_status_expiry_idx', 'payments_provider_status_idx'] as $index) {
            $this->assertTrue(Schema::hasIndex('payments', $index));
        }
        $event = WebhookEvent::create([
            'provider' => 'wompi', 'event_key' => 'fixture', 'event_type' => 'transaction.updated',
            'payload_hash' => str_repeat('a', 64), 'received_at' => now(), 'metadata' => ['safe' => true],
        ])->fresh();
        $this->assertSame('received', $event->status);
        $this->assertTrue($event->received_at->equalTo(now()));
        $this->assertSame(['safe' => true], $event->metadata);
        $this->expectException(UniqueConstraintViolationException::class);
        WebhookEvent::create($event->only(['provider', 'event_key', 'event_type', 'payload_hash', 'received_at']));
    }

    public function test_shipping_requires_address_and_charges_under_lock(): void
    {
        [$order, $token] = $this->fixture();
        $order->update(['fulfillment_type' => 'shipping']);
        $this->init($token)->assertConflict()->assertJsonPath('code', 'ORDER_NOT_READY_FOR_PAYMENT');
        $order->shippingAddress()->create([
            'type' => 'shipping', 'recipient_name' => 'Test', 'recipient_phone' => '3000000000', 'country_code' => 'CO',
            'state' => 'Atlántico', 'city' => 'Barranquilla', 'postal_code' => '080001', 'address_line1' => 'Calle 1',
        ]);
        $this->init($token)->assertConflict()->assertJsonPath('code', 'ORDER_NOT_READY_FOR_PAYMENT');
        $order->charges()->create(['type' => 'shipping', 'label' => 'Envío', 'amount' => 15000, 'currency' => 'COP']);
        $order->update(['charges_total' => 15000, 'total' => 255000]);
        $this->init($token)->assertCreated()->assertJsonPath('data.amount_in_cents', 25500000);
    }

    public function test_international_checkout_requires_landed_cost_before_payment(): void
    {
        [$order, $token] = $this->fixture();
        $order->update(['fulfillment_type' => 'shipping']);
        $order->shippingAddress()->create([
            'type' => 'shipping', 'recipient_name' => 'Test', 'recipient_phone' => '3000000000',
            'country_code' => 'US', 'state' => 'Florida', 'city' => 'Miami', 'postal_code' => '33101', 'address_line1' => 'Test',
        ]);
        $order->charges()->create(['type' => 'shipping', 'label' => 'Envío', 'amount' => 15000, 'currency' => 'COP']);
        $this->init($token)->assertConflict()->assertJsonPath('code', 'ORDER_NOT_READY_FOR_PAYMENT');
        $order->charges()->create(['type' => 'duty', 'label' => 'Arancel', 'amount' => 20000, 'currency' => 'COP']);
        $order->update(['charges_total' => 35000, 'total' => 275000]);
        $this->init($token)->assertCreated()->assertJsonPath('data.amount_in_cents', 27500000);
    }

    public function test_order_total_is_authoritative_even_when_catalog_price_changes(): void
    {
        [$order, $token] = $this->fixture();
        $order->items->first()->product->update(['price' => 999999]);
        $this->init($token)->assertCreated()->assertJsonPath('data.amount_in_cents', 24000000);
        $this->assertSame(240000, Payment::sole()->amount);
    }

    public function test_variant_reservation_changes_only_selected_branch_variant_stock(): void
    {
        [$order, $token, $simpleStock] = $this->fixture();
        $line = $order->items->first();
        $variant = ProductVariant::create([
            'product_id' => $line->product_id, 'name' => 'Variant QA', 'normalized_name' => 'variant-qa',
            'price' => 180000, 'is_active' => true, 'is_visible' => true,
        ]);
        $inventory = InventoryItem::firstOrCreate(['product_id' => null, 'product_variant_id' => $variant->id]);
        $variantStock = InventoryStock::create(['branch_id' => $order->branch_id, 'inventory_item_id' => $inventory->id, 'quantity' => 3]);
        $line->update(['product_variant_id' => $variant->id]);
        $this->init($token)->assertCreated();
        $this->assertSame(1, $variantStock->fresh()->quantity);
        $this->assertSame(5, $simpleStock->fresh()->quantity);
        $this->init($token, 'retry-variant')->assertCreated();
        $this->assertSame(1, $variantStock->fresh()->quantity);
        $this->assertDatabaseCount('inventory_movements', 1);
    }

    public function test_payment_key_is_independent_from_order_creation_and_case_sensitive(): void
    {
        [$order, $token] = $this->fixture();
        $this->init($token, $order->checkout_idempotency_key)->assertCreated();
        $this->init($token, 'CaseSensitive')->assertCreated();
        $this->init($token, 'casesensitive')->assertCreated();
        $this->assertDatabaseCount('payments', 3);
        $this->assertDatabaseCount('inventory_movements', 1);
    }

    /** @return array{Order, string, InventoryStock} */
    private function fixture(bool $ready = true): array
    {
        $identity = (string) Str::uuid();
        $branch = Branch::create(['name' => 'Sede QA', 'code' => substr($identity, 0, 8), 'slug' => $identity, 'city' => 'Barranquilla', 'is_active' => true]);
        $product = Product::create(['name' => 'Payment QA', 'slug' => $identity, 'sku' => $identity, 'price' => 120000,
            'is_active' => true, 'is_visible' => true, 'requires_shipping' => true]);
        $item = InventoryItem::firstOrCreate(['product_id' => $product->id, 'product_variant_id' => null]);
        $stock = InventoryStock::create(['branch_id' => $branch->id, 'inventory_item_id' => $item->id, 'quantity' => 5, 'minimum_quantity' => 0]);
        [$order, $token] = app(PublicCheckoutService::class)->create([
            'customer_name' => 'Cliente QA', 'customer_email' => 'qa@example.test', 'customer_phone' => '3000000000',
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ], 'order-'.$identity);
        if ($ready) {
            $order = app(PublicCheckoutService::class)->applyPickup($order, $branch->slug);
        }

        return [$order, $token, $stock];
    }

    private function init(string $token, string $key = 'payment-key', string $body = '{}'): TestResponse
    {
        return $this->call('POST', '/api/checkout/orders/'.$token.'/payments/wompi', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => $key,
        ], $body);
    }
}
