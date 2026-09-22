<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeCommission;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Services\EcommercePaymentService;
use App\Services\EcommerceStockReservationExpiryService;
use App\Services\PublicCheckoutService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class EcommerceStockReservationExpiryPhaseSevenTest extends TestCase
{
    use RefreshDatabase;

    public function createApplication()
    {
        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'upgrade_test',
            'TEST_DB_CONNECTION' => 'mysql', 'TEST_DB_DATABASE' => 'upgrade_test'] as $key => $value) {
            if (getenv($key) !== $value) {
                throw new RuntimeException('Explicit upgrade_test configuration required.');
            }
        }
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'upgrade_test'
            || DB::selectOne('SELECT DATABASE() AS name')->name !== 'upgrade_test') {
            throw new RuntimeException('Effective database must be upgrade_test.');
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Http::preventStrayRequests();
        $this->travelTo(now()->utc()->setDate(2026, 9, 21)->setTime(15, 0));
        config(['services.wompi' => [
            'base_url' => 'https://sandbox.wompi.co/v1', 'checkout_url' => 'https://checkout.wompi.co/p/',
            'public_key' => 'pub_test_fixture', 'private_key' => 'prv_test_fixture',
            'integrity_secret' => 'test_integrity_fixture', 'events_secret' => 'test_events_fixture',
            'redirect_url' => 'https://shop.example.test/return', 'reservation_minutes' => 30,
        ]]);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_expired_unpaid_reservation_is_cancelled_and_reversed_exactly_once(): void
    {
        [$order, $payment, $stock] = $this->fixture();
        $legacy = $order->only(['payment_provider', 'payment_reference', 'payment_transaction_id']);
        $paymentBefore = $payment->getAttributes();
        $this->expire($order);
        $this->assertCancelled($order, $stock, 5);
        $this->assertSame($legacy, $order->fresh()->only(array_keys($legacy)));
        $this->assertSame($paymentBefore, $payment->fresh()->getAttributes());
        $this->expire($order);
        $this->assertSame(1, $this->reversals($order));
        $this->assertSame(5, $stock->fresh()->quantity);
        $this->assertSame(0, EmployeeCommission::where('status', 'earned')->count());
        $this->assertSame('voided', EmployeeCommission::sole()->status);
    }

    #[DataProvider('nonBlockingPayments')]
    public function test_non_completed_payments_do_not_block_expiry(string $status, ?string $providerStatus): void
    {
        [$order, $payment, $stock] = $this->fixture();
        $payment->update(['status' => $status, 'provider_status' => $providerStatus]);
        $this->expire($order);
        $this->assertCancelled($order, $stock, 5);
        $this->assertSame($status, $payment->fresh()->status);
        $this->assertSame($providerStatus, $payment->fresh()->provider_status);
    }

    public static function nonBlockingPayments(): array
    {
        return [['pending', 'PENDING'], ['failed', 'DECLINED'], ['failed', 'ERROR'], ['failed', 'VOIDED']];
    }

    public function test_valid_full_credit_keeps_order_confirmed_paid_and_clears_reservation_without_extending_it(): void
    {
        [$order, $payment, $stock] = $this->fixture();
        $payment->update(['status' => 'completed', 'provider_status' => 'APPROVED', 'transaction_id' => 'tx-paid', 'paid_at' => now()]);
        $order->update(['payment_status' => 'unpaid']);
        $this->expire($order);
        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertNull($order->fresh()->stock_reservation_expires_at);
        $this->assertNull($order->fresh()->stock_reverted_at);
        $this->assertSame(3, $stock->fresh()->quantity);
        $this->assertSame(0, $this->reversals($order));
        $this->assertSame('completed', $payment->fresh()->status);
    }

    public function test_reconciliation_blocks_expiry_without_new_financial_or_stock_writes(): void
    {
        [$order, $payment, $stock] = $this->fixture();
        $payment->update(['reconciliation_required_at' => now(), 'reconciliation_reason' => 'LATE_APPROVAL']);
        $before = $this->snapshot($order);
        $this->artisan('ecommerce:expire-stock-reservations')->expectsOutputToContain('conciliacion=1')->assertSuccessful();
        $this->assertSame($before, $this->snapshot($order));
        $this->assertSame(3, $stock->fresh()->quantity);
    }

    #[DataProvider('ineligibleOrders')]
    public function test_ineligible_orders_are_not_candidates_or_mutated(string $field, mixed $value): void
    {
        [$order, $payment, $stock] = $this->fixture();
        $order->update([$field => $value]);
        $before = $this->snapshot($order);
        $this->artisan('ecommerce:expire-stock-reservations')->assertSuccessful();
        $this->assertSame($before, $this->snapshot($order));
        $this->assertSame(3, $stock->fresh()->quantity);
        $this->assertSame('pending', $payment->fresh()->status);
    }

    public static function ineligibleOrders(): array
    {
        return [
            ['status', 'pending'], ['status', 'completed'], ['status', 'cancelled'], ['origin', 'crm'],
            ['stock_reservation_expires_at', now()->addMinute()], ['stock_reservation_expires_at', null],
        ];
    }

    public function test_two_expiry_workers_are_idempotent_after_first_cancellation(): void
    {
        [$order, , $stock] = $this->fixture();
        $service = app(EcommerceStockReservationExpiryService::class);
        $this->assertSame('expired', $service->expire($order->id));
        $this->assertSame('skipped', $service->expire($order->id));
        $this->assertSame(1, $this->reversals($order));
        $this->assertSame(5, $stock->fresh()->quantity);
    }

    public function test_approved_before_expiry_wins_and_expiry_observes_paid_order(): void
    {
        [$order, $payment, $stock] = $this->fixture();
        $event = $this->event($payment, 'APPROVED');
        Http::fake(['*' => Http::response(['data' => $event['data']['transaction']])]);
        $this->postJson('/api/webhooks/wompi', $event)->assertOk()->assertJsonPath('status', 'processed');
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('paid', $this->expire($order));
        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertNull($order->fresh()->stock_reservation_expires_at);
        $this->assertSame(0, $this->reversals($order));
        $this->assertSame(3, $stock->fresh()->quantity);
    }

    public function test_expiry_before_approved_keeps_cancellation_and_routes_late_approval_to_reconciliation(): void
    {
        [$order, $payment, $stock] = $this->fixture();
        $this->assertSame('expired', $this->expire($order));
        $event = $this->event($payment, 'APPROVED');
        Http::fake(['*' => Http::response(['data' => $event['data']['transaction']])]);
        $this->postJson('/api/webhooks/wompi', $event)->assertOk()->assertJsonPath('status', 'requires_reconciliation');
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame('APPROVED', $payment->fresh()->provider_status);
        $this->assertSame('LATE_APPROVAL', $payment->fresh()->reconciliation_reason);
        $this->assertNotNull($payment->fresh()->reconciliation_required_at);
        $this->assertSame(1, $this->reversals($order));
        $this->assertSame(5, $stock->fresh()->quantity);
    }

    public function test_command_is_scheduled_every_minute_without_overlap(): void
    {
        $event = collect(Schedule::events())->first(fn ($event) => $event->command === "'/usr/bin/php8.3' 'artisan' ecommerce:expire-stock-reservations");
        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertNotNull($event->withoutOverlapping);
    }

    private function expire(Order $order): string
    {
        return app(EcommerceStockReservationExpiryService::class)->expire($order->id);
    }

    private function fixture(): array
    {
        $id = (string) Str::uuid();
        $branch = Branch::create(['name' => 'QA', 'code' => substr($id, 0, 8), 'slug' => $id, 'city' => 'Barranquilla', 'is_active' => true]);
        $seller = Employee::create(['branch_id' => $branch->id, 'name' => 'Seller QA', 'job_title' => 'sales', 'is_active' => true]);
        $product = Product::create(['name' => 'Expiry QA', 'slug' => $id, 'sku' => $id, 'price' => 120000,
            'is_active' => true, 'is_visible' => true, 'requires_shipping' => true, 'commission_enabled' => true, 'commission_amount' => 2000]);
        $item = InventoryItem::firstOrCreate(['product_id' => $product->id, 'product_variant_id' => null]);
        $stock = InventoryStock::create(['branch_id' => $branch->id, 'inventory_item_id' => $item->id, 'quantity' => 5]);
        [$order, $token] = app(PublicCheckoutService::class)->create([
            'customer_name' => 'QA', 'customer_email' => 'qa@example.test', 'customer_phone' => '3000000000',
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ], 'order-'.$id);
        $order->update(['sales_employee_id' => $seller->id, 'payment_provider' => 'legacy-untouched',
            'payment_reference' => 'legacy-reference', 'payment_transaction_id' => 'legacy-transaction']);
        app(PublicCheckoutService::class)->applyPickup($order, $branch->slug);
        app(EcommercePaymentService::class)->initialize($token, 'payment-'.$id);
        $order = $order->fresh();
        $order->update(['stock_reservation_expires_at' => now()->subSecond()]);

        return [$order->fresh(), Payment::sole(), $stock];
    }

    private function event(Payment $payment, string $status): array
    {
        $transaction = ['id' => 'tx-normal', 'status' => $status, 'reference' => $payment->reference,
            'amount_in_cents' => $payment->amount * 100, 'currency' => 'COP'];

        return ['event' => 'transaction.updated', 'environment' => 'test', 'timestamp' => 1790000000,
            'data' => ['transaction' => $transaction], 'signature' => ['properties' => ['transaction.id', 'transaction.status', 'transaction.amount_in_cents'],
                'checksum' => hash('sha256', 'tx-normal'.$status.$transaction['amount_in_cents'].'1790000000test_events_fixture')]];
    }

    private function reversals(Order $order): int
    {
        return InventoryMovement::where('reference_type', Order::class)->where('reference_id', $order->id)
            ->where('type', InventoryMovement::TYPE_SALE_REVERSAL)->count();
    }

    private function assertCancelled(Order $order, InventoryStock $stock, int $quantity): void
    {
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertNotNull($order->fresh()->stock_reverted_at);
        $this->assertSame($quantity, $stock->fresh()->quantity);
        $this->assertSame(1, $this->reversals($order));
    }

    private function snapshot(Order $order): array
    {
        return json_decode(json_encode([
            $order->fresh()->getAttributes(),
            DB::table('payments')->orderBy('id')->get(),
            DB::table('inventory_movements')->orderBy('id')->get(),
            DB::table('employee_commissions')->orderBy('id')->get(),
        ]), true);
    }
}
