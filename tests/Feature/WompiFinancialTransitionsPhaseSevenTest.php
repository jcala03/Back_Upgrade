<?php

namespace Tests\Feature;

use App\Exceptions\WompiWebhookException;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeCommission;
use App\Models\InventoryItem;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\WebhookEvent;
use App\Services\EcommercePaymentService;
use App\Services\PublicCheckoutService;
use App\Services\WompiPaymentTransitionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class WompiFinancialTransitionsPhaseSevenTest extends TestCase
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

    #[DataProvider('statuses')]
    public function test_normal_states_and_all_stock_commission_legacy_invariants(string $status, string $paymentStatus, string $orderStatus): void
    {
        [$payment, $order] = $this->fixture();
        $before = $this->invariants($order);
        $beforePayment = $payment->getAttributes();
        $event = $this->event($payment, $status);
        $this->fake($event);
        $this->postJson('/api/webhooks/wompi', $event)->assertOk()->assertExactJson(['status' => 'processed']);
        $payment->refresh();
        $order->refresh();
        $this->assertSame($paymentStatus, $payment->status);
        $this->assertSame($status, $payment->provider_status);
        $this->assertSame('tx-normal', $payment->transaction_id);
        $this->assertSame($orderStatus, $order->payment_status);
        $this->assertSame('confirmed', $order->status);
        $this->assertNull($order->completed_at);
        $this->assertSame($before, $this->invariants($order));
        $this->assertSame('pending', EmployeeCommission::sole()->status);
        $this->assertNull(EmployeeCommission::sole()->earned_at);
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertSame(3, InventoryStock::sole()->quantity);
        $this->assertSame($beforePayment['expires_at'], $payment->getRawOriginal('expires_at'));
        $this->assertSame($beforePayment['metadata'], $payment->getRawOriginal('metadata'));
        if ($status === 'APPROVED') {
            $this->assertTrue($payment->paid_at->equalTo(now()));
            $this->assertFalse($order->canRetryPayment());
        } else {
            $this->assertNull($payment->paid_at);
            $this->assertTrue($order->canRetryPayment());
        }
        if (in_array($status, ['DECLINED', 'ERROR', 'VOIDED'], true)) {
            $this->assertSame('WOMPI_'.$status, $payment->failure_code);
            $this->assertNotNull($payment->failure_reason);
        } else {
            $this->assertNull($payment->failure_code);
            $this->assertNull($payment->failure_reason);
        }
        $ledger = WebhookEvent::sole();
        $this->assertSame('processed', $ledger->status);
        $this->assertTrue($ledger->processed_at->equalTo(now()));
        $this->assertNull($ledger->last_error);
        $serialized = json_encode([$payment->failure_reason, $payment->failure_code, $ledger->metadata]);
        foreach (['prv_test_fixture', 'test_events_fixture', 'customer@example.test'] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $serialized);
        }
    }

    public static function statuses(): array
    {
        return [['PENDING', 'pending', 'unpaid'], ['APPROVED', 'completed', 'paid'],
            ['DECLINED', 'failed', 'unpaid'], ['ERROR', 'failed', 'unpaid'], ['VOIDED', 'failed', 'unpaid']];
    }

    #[DataProvider('statuses')]
    public function test_repeated_deliveries_and_distinct_envelopes_are_idempotent(string $status, string $paymentStatus, string $orderStatus): void
    {
        [$payment, $order] = $this->fixture();
        $event = $this->event($payment, $status);
        $this->fake($event);
        $this->postJson('/api/webhooks/wompi', $event)->assertOk();
        $before = [$payment->fresh()->getAttributes(), $order->fresh()->getAttributes(), $this->invariants($order)];
        $processed = WebhookEvent::sole()->getAttributes();
        $this->travel(2)->minutes();
        $this->postJson('/api/webhooks/wompi', $event)->assertOk()->assertJsonPath('status', 'processed');
        $this->assertSame($processed, WebhookEvent::sole()->getAttributes());
        Http::assertSentCount(1);
        // Different key (optional currency omitted), same verified transaction and financial result.
        unset($event['data']['transaction']['currency']);
        $this->postJson('/api/webhooks/wompi', $event)->assertOk()->assertJsonPath('status', 'processed');
        Http::assertSentCount(2);
        $this->assertSame($before, [$payment->fresh()->getAttributes(), $order->fresh()->getAttributes(), $this->invariants($order)]);
        $this->assertSame($paymentStatus, $payment->fresh()->status);
        $this->assertSame($orderStatus, $order->fresh()->payment_status);
        $this->assertDatabaseCount('webhook_events', 2);
    }

    #[DataProvider('terminalStatuses')]
    public function test_pending_can_transition_to_a_normal_terminal_state(string $status): void
    {
        [$payment, $order] = $this->fixture();
        $pending = $this->event($payment, 'PENDING');
        $terminal = $this->event($payment, $status);
        Http::fake(['*' => Http::sequence()->push(['data' => $pending['data']['transaction']])->push(['data' => $terminal['data']['transaction']])]);
        $before = $this->invariants($order);
        $this->postJson('/api/webhooks/wompi', $pending)->assertOk()->assertJsonPath('status', 'processed');
        $this->travel(1)->minutes();
        $this->postJson('/api/webhooks/wompi', $terminal)->assertOk()->assertJsonPath('status', 'processed');
        $this->assertSame($status === 'APPROVED' ? 'completed' : 'failed', $payment->fresh()->status);
        $this->assertSame($before, $this->invariants($order));
    }

    public static function terminalStatuses(): array
    {
        return [['APPROVED'], ['DECLINED'], ['ERROR'], ['VOIDED']];
    }

    #[DataProvider('crashPoints')]
    public function test_verified_event_crash_rolls_back_and_retry_processes_once(string $point): void
    {
        [$payment, $order] = $this->fixture();
        $event = $this->event($payment, 'APPROVED');
        $ledger = $this->verified($event);
        $this->fake($event);
        $before = [$payment->fresh()->getAttributes(), $order->fresh()->getAttributes(), $this->invariants($order)];
        $crash = true;
        $callback = function ($model) use (&$crash, $point) {
            if ($crash && ($point === 'order' || $model->status === 'processed')) {
                $crash = false;
                throw new RuntimeException('Simulated crash');
            }
        };
        if ($point === 'order') {
            Order::updating($callback);
        } elseif ($point === 'ledger_after_write') {
            WebhookEvent::updated($callback);
        } else {
            WebhookEvent::updating($callback);
        }
        $this->withoutExceptionHandling();
        try {
            $this->postJson('/api/webhooks/wompi', $event);
            $this->fail('Crash must propagate and roll back.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated crash', $exception->getMessage());
        }
        $this->assertSame($before, [$payment->fresh()->getAttributes(), $order->fresh()->getAttributes(), $this->invariants($order)]);
        $this->assertSame('verified', $ledger->fresh()->status);
        $this->assertNull($ledger->fresh()->processed_at);
        $this->postJson('/api/webhooks/wompi', $event)->assertOk()->assertJsonPath('status', 'processed');
        $paidAt = $payment->fresh()->paid_at->toIso8601String();
        $this->travel(1)->minutes();
        $this->postJson('/api/webhooks/wompi', $event)->assertOk();
        $this->assertSame($paidAt, $payment->fresh()->paid_at->toIso8601String());
        $this->assertDatabaseCount('webhook_events', 1);
        Http::assertSentCount(2);
    }

    public static function crashPoints(): array
    {
        return [['order'], ['ledger_before_write'], ['ledger_after_write']];
    }

    public function test_handled_failure_after_writes_rolls_back_savepoint_and_is_retryable(): void
    {
        [$payment, $order] = $this->fixture();
        $event = $this->event($payment, 'APPROVED');
        $this->fake($event);
        $before = [$payment->getAttributes(), $order->getAttributes()];
        $real = app(WompiPaymentTransitionService::class);
        $calls = 0;
        $this->mock(WompiPaymentTransitionService::class, function ($mock) use ($real, &$calls) {
            $mock->shouldReceive('apply')->twice()->andReturnUsing(function ($payment, $order, $transaction) use ($real, &$calls) {
                $real->apply($payment, $order, $transaction);
                if (++$calls === 1) {
                    throw new WompiWebhookException('WOMPI_TEMPORARY_TEST_FAILURE', 503);
                }
            });
        });
        $this->postJson('/api/webhooks/wompi', $event)->assertStatus(503);
        $this->assertSame($before, [$payment->fresh()->getAttributes(), $order->fresh()->getAttributes()]);
        $this->assertSame('failed', WebhookEvent::sole()->status);
        $this->assertNull(WebhookEvent::sole()->processed_at);
        $this->assertTrue(WebhookEvent::sole()->metadata['retryable']);
        $this->postJson('/api/webhooks/wompi', $event)->assertOk()->assertJsonPath('status', 'processed');
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_unique_provider_transaction_conflict_is_safely_audited(): void
    {
        [$payment, $order] = $this->fixture();
        $other = Payment::create(['order_id' => $order->id, 'method' => 'wompi', 'provider' => 'wompi',
            'reference' => 'another-attempt', 'amount' => $payment->amount, 'status' => 'pending', 'transaction_id' => 'tx-normal']);
        $before = [$payment->fresh()->getAttributes(), $other->fresh()->getAttributes(), $order->getAttributes(), $this->invariants($order)];
        $event = $this->event($payment, 'APPROVED');
        $this->fake($event);
        $this->postJson('/api/webhooks/wompi', $event)->assertOk()->assertExactJson(['status' => 'requires_reconciliation']);
        $this->assertSame('TRANSACTION_CONFLICT', $payment->fresh()->reconciliation_reason);
        $this->assertNotNull(WebhookEvent::sole()->processed_at);
        $before[0]['reconciliation_required_at'] = $payment->fresh()->getRawOriginal('reconciliation_required_at');
        $before[0]['reconciliation_reason'] = 'TRANSACTION_CONFLICT';
        $this->assertSame($before, [$payment->fresh()->getAttributes(), $other->fresh()->getAttributes(), $order->fresh()->getAttributes(), $this->invariants($order)]);
    }

    #[DataProvider('outOfScope')]
    public function test_out_of_scope_states_are_rejected_without_financial_or_inventory_changes(string $case): void
    {
        [$payment, $order] = $this->fixture();
        $status = 'APPROVED';
        switch ($case) {
            case 'unreserved': $order->update(['stock_committed_at' => null]);
                break;
            case 'reverted': $order->update(['stock_reverted_at' => now()]);
                break;
            case 'cancelled': $order->update(['status' => 'cancelled']);
                break;
            case 'reconciliation': $payment->update(['reconciliation_required_at' => now()]);
                break;
            case 'other_completed': Payment::create(['order_id' => $order->id, 'amount' => 1, 'method' => 'cash', 'status' => 'completed']);
                break;
            case 'void_after_approved': $payment->update(['status' => 'completed', 'provider_status' => 'APPROVED', 'transaction_id' => 'tx-normal', 'paid_at' => now()]);
                $order->update(['payment_status' => 'paid']);
                $status = 'VOIDED';
                break;
            case 'pending_after_failed': $payment->update(['status' => 'failed', 'provider_status' => 'DECLINED']);
                $status = 'PENDING';
                break;
            case 'total_changed': $order->update(['total' => $order->total + 1]);
                break;
            case 'transaction_id': $payment->update(['transaction_id' => 'other-id']);
                break;
        }
        $before = [$payment->fresh()->getAttributes(), $order->fresh()->getAttributes(), $this->invariants($order)];
        $event = $this->event($payment, $status);
        $this->fake($event);
        $reconciled = in_array($case, ['reverted', 'cancelled', 'other_completed', 'void_after_approved', 'transaction_id'], true);
        $this->postJson('/api/webhooks/wompi', $event)->assertOk()->assertJsonPath('status', $reconciled ? 'requires_reconciliation' : 'failed');
        if ($reconciled) {
            $this->assertNotNull($payment->fresh()->reconciliation_required_at);
            $this->assertNotNull(WebhookEvent::sole()->processed_at);
            $this->assertSame($before[2], $this->invariants($order));
            $this->assertSame($case === 'void_after_approved' ? 'refunded' : 'pending', $payment->fresh()->status);
            $this->assertSame($case === 'void_after_approved' ? 'unpaid' : $before[1]['payment_status'], $order->fresh()->payment_status);
        } else {
            $this->assertSame($before, [$payment->fresh()->getAttributes(), $order->fresh()->getAttributes(), $this->invariants($order)]);
            $this->assertNull(WebhookEvent::sole()->processed_at);
        }
    }

    public static function outOfScope(): array
    {
        return [['unreserved'], ['reverted'], ['cancelled'], ['reconciliation'], ['other_completed'],
            ['void_after_approved'], ['pending_after_failed'], ['total_changed'], ['transaction_id']];
    }

    public function test_internal_provider_query_error_is_not_a_financial_error_status(): void
    {
        [$payment, $order] = $this->fixture();
        $before = [$payment->getAttributes(), $order->getAttributes(), $this->invariants($order)];
        Http::fake(['*' => Http::response([], 500)]);
        $this->postJson('/api/webhooks/wompi', $this->event($payment, 'ERROR'))->assertStatus(503);
        $this->assertSame($before, [$payment->fresh()->getAttributes(), $order->fresh()->getAttributes(), $this->invariants($order)]);
        $this->assertNull(WebhookEvent::sole()->processed_at);
    }

    #[DataProvider('securityGates')]
    public function test_security_gates_prevent_financial_writes_for_existing_payment(string $gate): void
    {
        [$payment, $order] = $this->fixture();
        $before = [$payment->getAttributes(), $order->getAttributes(), $this->invariants($order)];
        $event = $this->event($payment, $gate === 'unknown' ? 'FUTURE_STATUS' : 'APPROVED');
        $provider = $event['data']['transaction'];
        $http = 200;
        if ($gate === 'checksum') {
            $event['signature']['checksum'] = str_repeat('0', 64);
            $http = 401;
        } elseif ($gate === 'environment') {
            $event['environment'] = 'prod';
            $http = 400;
        } elseif ($gate === 'amount') {
            $provider['amount_in_cents']++;
        }
        Http::fake(['*' => Http::response(['data' => $provider])]);
        $this->postJson('/api/webhooks/wompi', $event)->assertStatus($http);
        $this->assertSame($before, [$payment->fresh()->getAttributes(), $order->fresh()->getAttributes(), $this->invariants($order)]);
        if ($http !== 200) {
            Http::assertNothingSent();
            $this->assertDatabaseCount('webhook_events', 0);
        } else {
            $this->assertNull(WebhookEvent::sole()->processed_at);
            $this->assertNotSame('processed', WebhookEvent::sole()->status);
        }
    }

    public static function securityGates(): array
    {
        return [['checksum'], ['environment'], ['amount'], ['unknown']];
    }

    public function test_lock_order_and_revalidation_use_current_locked_records(): void
    {
        [$payment, $order] = $this->fixture();
        $locks = [];
        DB::listen(function ($query) use (&$locks) {
            if (str_contains($query->sql, 'for update')) {
                $locks[] = $query->sql;
            }
        });
        $event = $this->event($payment, 'APPROVED');
        Http::fake(function () use ($order, $event) {
            // Simulate a state change before Payment/Order locks are acquired.
            $order->update(['status' => 'cancelled']);

            return Http::response(['data' => $event['data']['transaction']]);
        });
        $this->postJson('/api/webhooks/wompi', $event)->assertOk()->assertJsonPath('status', 'requires_reconciliation');
        $this->assertCount(3, $locks);
        foreach (['webhook_events', 'payments', 'orders'] as $index => $table) {
            $this->assertStringContainsString('from `'.$table.'`', $locks[$index]);
        }
        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame('tx-normal', $payment->fresh()->transaction_id);
    }

    private function fixture(): array
    {
        $id = (string) Str::uuid();
        $branch = Branch::create(['name' => 'QA', 'code' => substr($id, 0, 8), 'slug' => $id, 'city' => 'Barranquilla', 'is_active' => true]);
        $seller = Employee::create(['branch_id' => $branch->id, 'name' => 'Seller QA', 'job_title' => 'sales', 'is_active' => true]);
        $product = Product::create(['name' => 'Financial QA', 'slug' => $id, 'sku' => $id, 'price' => 120000,
            'is_active' => true, 'is_visible' => true, 'requires_shipping' => true, 'commission_enabled' => true, 'commission_amount' => 2000]);
        $item = InventoryItem::firstOrCreate(['product_id' => $product->id, 'product_variant_id' => null]);
        InventoryStock::create(['branch_id' => $branch->id, 'inventory_item_id' => $item->id, 'quantity' => 5]);
        [$order, $token] = app(PublicCheckoutService::class)->create([
            'customer_name' => 'QA', 'customer_email' => 'qa@example.test', 'customer_phone' => '3000000000',
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ], 'order-'.$id);
        $order->update(['sales_employee_id' => $seller->id, 'payment_provider' => 'legacy-untouched',
            'payment_reference' => 'legacy-reference', 'payment_transaction_id' => 'legacy-transaction']);
        app(PublicCheckoutService::class)->applyPickup($order, $branch->slug);
        app(EcommercePaymentService::class)->initialize($token, 'payment-'.$id);
        $this->assertSame('pending', EmployeeCommission::sole()->status);

        return [Payment::sole(), $order->fresh()];
    }

    private function event(Payment $payment, string $status): array
    {
        $transaction = ['id' => 'tx-normal', 'status' => $status, 'reference' => $payment->reference, 'amount_in_cents' => $payment->amount * 100,
            'currency' => 'COP', 'status_message' => 'customer@example.test prv_test_fixture test_events_fixture'];

        return ['event' => 'transaction.updated', 'environment' => 'test', 'timestamp' => 1790000000,
            'data' => ['transaction' => $transaction], 'signature' => ['properties' => ['transaction.id', 'transaction.status', 'transaction.amount_in_cents'],
                'checksum' => hash('sha256', 'tx-normal'.$status.$transaction['amount_in_cents'].'1790000000test_events_fixture')]];
    }

    private function fake(array $event): void
    {
        Http::fake(['*' => Http::response(['data' => $event['data']['transaction']])]);
    }

    private function verified(array $event): WebhookEvent
    {
        $transaction = $event['data']['transaction'];

        return WebhookEvent::create(['provider' => 'wompi', 'event_type' => 'transaction.updated', 'provider_transaction_id' => $transaction['id'],
            'event_key' => hash('sha256', json_encode(['test', 'transaction.updated', $transaction['id'], $transaction['status'],
                $transaction['reference'], $transaction['amount_in_cents'], $transaction['currency']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'payload_hash' => hash('sha256', json_encode($event)), 'status' => 'verified', 'received_at' => now()->subMinute(), 'metadata' => ['environment' => 'test']]);
    }

    private function invariants(Order $order): array
    {
        $snapshot = ['order' => $order->fresh()->only(['status', 'completed_at', 'stock_committed_at', 'stock_reverted_at', 'stock_reservation_expires_at',
            'payment_provider', 'payment_reference', 'payment_transaction_id'])];
        foreach (['inventory_stocks', 'inventory_movements', 'employee_commissions', 'order_status_histories'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        // Dates normalized to strings, so equality checks compare values rather than Carbon object identity.
        return json_decode(json_encode($snapshot), true);
    }
}
