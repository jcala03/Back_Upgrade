<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CrmNotification;
use App\Models\Employee;
use App\Models\EmployeeCommission;
use App\Models\InventoryItem;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentReconciliationAction;
use App\Models\PaymentReconciliationReview;
use App\Models\Product;
use App\Models\User;
use App\Models\UserCapability;
use App\Models\WebhookEvent;
use App\Services\EcommercePaymentService;
use App\Services\OrderService;
use App\Services\PaymentReconciliationResolutionService;
use App\Services\PublicCheckoutService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class WompiReconciliationPhaseSevenTest extends TestCase
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

    #[DataProvider('lateModes')]
    public function test_late_approval_records_money_without_reactivation_or_credit(string $mode): void
    {
        [$payment, $order, $token] = $this->fixture();
        if ($mode === 'cancelled_real') {
            app(OrderService::class)->transition($order, 'cancelled', null);
        } elseif ($mode === 'cancelled_only') {
            $order->update(['status' => 'cancelled']);
        } else {
            $order->update(['stock_reverted_at' => now()]);
        }
        $orderBefore = $order->fresh()->getAttributes();
        $before = $this->invariants($order);
        $event = $this->event($payment, 'APPROVED');
        $this->fake($event);
        $this->postJson('/api/webhooks/wompi', $event)->assertOk()->assertExactJson(['status' => 'requires_reconciliation']);
        $payment->refresh();
        $this->assertSame('pending', $payment->status);
        $this->assertSame('APPROVED', $payment->provider_status);
        $this->assertSame('tx-normal', $payment->transaction_id);
        $this->assertSame('LATE_APPROVAL', $payment->reconciliation_reason);
        $this->assertNotNull($payment->reconciliation_required_at);
        $this->assertReview($payment, 'LATE_APPROVAL');
        $this->assertNull($payment->paid_at);
        $this->assertSame($orderBefore, $order->fresh()->getAttributes());
        $this->assertSame($before, $this->invariants($order));
        $this->assertSame('unpaid', $order->fresh()->payment_status);
        $this->assertSame(0, EmployeeCommission::where('status', 'earned')->count());
        $this->assertTerminal();
        $this->assertRetryBlocked($token);
    }

    public static function lateModes(): array
    {
        return [['cancelled_real'], ['cancelled_only'], ['reverted_only']];
    }

    public function test_second_approved_payment_is_held_for_review_without_changing_first_payment(): void
    {
        [$first, $order, $token] = $this->fixture();
        $second = $this->another($first);
        $this->complete($first, 'tx-first');
        $beforeFirst = $first->fresh()->getAttributes();
        $beforeOrder = $order->fresh()->getAttributes();
        $before = $this->invariants($order);
        $event = $this->event($second, 'APPROVED');
        $this->fake($event);
        $this->postJson('/api/webhooks/wompi', $event)->assertOk()->assertJsonPath('status', 'requires_reconciliation');
        $this->assertSame('DUPLICATE_APPROVAL', $second->fresh()->reconciliation_reason);
        $this->assertSame('pending', $second->fresh()->status);
        $this->assertSame('APPROVED', $second->fresh()->provider_status);
        $this->assertSame('tx-normal', $second->fresh()->transaction_id);
        $this->assertReview($second, 'DUPLICATE_APPROVAL');
        $this->assertSame($beforeFirst, $first->fresh()->getAttributes());
        $this->assertSame($beforeOrder, $order->fresh()->getAttributes());
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame($before, $this->invariants($order));
        $this->assertTerminal();
        $this->assertRetryBlocked($token);
    }

    #[DataProvider('remainingCredits')]
    public function test_void_after_approval_recalculates_only_valid_remaining_credit(string $other, string $expected): void
    {
        [$payment, $order, $token] = $this->fixture();
        $this->complete($payment, 'tx-normal');
        $paidAt = $payment->fresh()->paid_at->toIso8601String();
        $credit = null;
        if ($other !== 'none') {
            $credit = $this->another($payment);
            $credit->update(['status' => 'completed', 'provider_status' => 'APPROVED', 'transaction_id' => 'tx-other',
                'amount' => $other === 'partial' ? 100 : $payment->amount]);
            if ($other === 'review') {
                $credit->update(['reconciliation_required_at' => now()]);
            } elseif ($other === 'refunded') {
                $credit->update(['status' => 'refunded']);
            } elseif ($other === 'currency') {
                $credit->update(['currency' => 'USD']);
            }
        }
        $creditBefore = $credit?->fresh()->getAttributes();
        $before = $this->invariants($order);
        $event = $this->event($payment, 'VOIDED');
        $this->fake($event);
        $this->postJson('/api/webhooks/wompi', $event)->assertOk()->assertExactJson(['status' => 'requires_reconciliation']);
        $this->assertSame('refunded', $payment->fresh()->status);
        $this->assertSame('VOIDED', $payment->fresh()->provider_status);
        $this->assertSame('tx-normal', $payment->fresh()->transaction_id);
        $this->assertSame('VOID_AFTER_APPROVAL', $payment->fresh()->reconciliation_reason);
        $this->assertReview($payment, 'VOID_AFTER_APPROVAL');
        $this->assertSame($paidAt, $payment->fresh()->paid_at->toIso8601String());
        $this->assertSame($expected, $order->fresh()->payment_status);
        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertSame($before, $this->invariants($order));
        $this->assertSame($creditBefore, $credit?->fresh()->getAttributes());
        $this->assertTerminal();
        $this->assertRetryBlocked($token);
    }

    public static function remainingCredits(): array
    {
        return [['none', 'unpaid'], ['full', 'paid'], ['partial', 'partial'], ['review', 'unpaid'], ['refunded', 'unpaid'], ['currency', 'unpaid']];
    }

    #[DataProvider('conflicts')]
    public function test_transaction_conflict_preserves_ownership_and_financial_fields(string $mode): void
    {
        [$payment, $order, $token] = $this->fixture();
        $owner = null;
        if ($mode === 'existing') {
            $payment->update(['transaction_id' => 'old-id']);
        } else {
            $owner = $this->another($payment);
            $owner->update(['transaction_id' => 'tx-normal']);
        }
        $beforePayment = $payment->fresh()->getAttributes();
        $beforeOwner = $owner?->fresh()->getAttributes();
        $beforeOrder = $order->fresh()->getAttributes();
        $before = $this->invariants($order);
        $event = $this->event($payment, 'APPROVED');
        $this->fake($event);
        $this->postJson('/api/webhooks/wompi', $event)->assertOk()->assertJsonPath('status', 'requires_reconciliation');
        $this->assertSame('TRANSACTION_CONFLICT', $payment->fresh()->reconciliation_reason);
        $this->assertNotNull($payment->fresh()->reconciliation_required_at);
        $this->assertReview($payment, 'TRANSACTION_CONFLICT');
        $after = $payment->fresh()->getAttributes();
        foreach (['reconciliation_reason', 'reconciliation_required_at', 'updated_at'] as $key) {
            unset($beforePayment[$key], $after[$key]);
        }
        $this->assertSame($beforePayment, $after);
        $this->assertSame($beforeOwner, $owner?->fresh()->getAttributes());
        $this->assertSame($beforeOrder, $order->fresh()->getAttributes());
        $this->assertSame($before, $this->invariants($order));
        $this->assertSame($mode === 'unique' ? 1 : 0, Payment::where('transaction_id', 'tx-normal')->count());
        $this->assertTerminal();
        $this->assertRetryBlocked($token);
    }

    public static function conflicts(): array
    {
        return [['existing'], ['unique']];
    }

    #[DataProvider('reasons')]
    public function test_alerts_are_permission_scoped_sanitized_and_idempotent(string $reason): void
    {
        [$payment, $order] = $this->fixture();
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $admin2 = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        User::factory()->create(['role' => 'admin', 'is_active' => false]);
        $seller = User::factory()->create(['role' => 'user', 'is_active' => true]);
        UserCapability::create(['user_id' => $seller->id, 'capability' => UserCapability::PAYMENTS_CREATE_OWN, 'granted_by' => $admin->id]);
        $status = 'APPROVED';
        if ($reason === 'LATE_APPROVAL') {
            $order->update(['status' => 'cancelled']);
        } elseif ($reason === 'DUPLICATE_APPROVAL') {
            $this->complete($this->another($payment), 'tx-first');
        } elseif ($reason === 'VOID_AFTER_APPROVAL') {
            $this->complete($payment, 'tx-normal');
            $status = 'VOIDED';
        } else {
            $payment->update(['transaction_id' => 'old-id']);
        }
        $event = $this->event($payment, $status);
        $this->fake($event);
        $response = $this->postJson('/api/webhooks/wompi', $event)->assertOk()->assertExactJson(['status' => 'requires_reconciliation']);
        $alerts = CrmNotification::where('type', CrmNotification::TYPE_PAYMENT_RECONCILIATION_REQUIRED)->orderBy('user_id')->get();
        $this->assertSame([$admin->id, $admin2->id], $alerts->pluck('user_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(['payment_id' => $payment->id, 'order_id' => $order->id, 'reason' => $reason], $alerts->first()->data);
        foreach (['customer@example.test', 'prv_test_fixture', 'test_events_fixture', 'legacy-reference', 'tx-normal', 'Authorization'] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $alerts->toJson().$response->getContent());
        }
        $before = [$payment->fresh()->getAttributes(), $order->fresh()->getAttributes(), $alerts->toJson()];
        $timestamp = WebhookEvent::sole()->processed_at->toIso8601String();
        $this->travel(5)->minutes();
        $this->postJson('/api/webhooks/wompi', $event)->assertOk();
        $this->assertSame($timestamp, WebhookEvent::sole()->processed_at->toIso8601String());
        $this->assertDatabaseCount('payment_reconciliation_reviews', 1);
        Http::assertSentCount(1);
        unset($event['data']['transaction']['currency']);
        $this->postJson('/api/webhooks/wompi', $event)->assertOk()->assertJsonPath('status', 'requires_reconciliation');
        $this->assertDatabaseCount('payment_reconciliation_reviews', 2);
        Http::assertSentCount(2);
        $this->assertSame($before, [$payment->fresh()->getAttributes(), $order->fresh()->getAttributes(),
            CrmNotification::where('type', CrmNotification::TYPE_PAYMENT_RECONCILIATION_REQUIRED)->orderBy('user_id')->get()->toJson()]);
    }

    public static function reasons(): array
    {
        return [['LATE_APPROVAL'], ['DUPLICATE_APPROVAL'], ['VOID_AFTER_APPROVAL'], ['TRANSACTION_CONFLICT']];
    }

    #[DataProvider('staleStatuses')]
    public function test_stale_pending_and_declined_cannot_downgrade_completed(string $status): void
    {
        [$payment, $order] = $this->fixture();
        $this->complete($payment, 'tx-normal');
        $before = [$payment->fresh()->getAttributes(), $order->fresh()->getAttributes(), $this->invariants($order)];
        $event = $this->event($payment, $status);
        $this->fake($event);
        $this->postJson('/api/webhooks/wompi', $event)->assertOk()->assertJsonPath('status', 'failed');
        $this->assertSame($before, [$payment->fresh()->getAttributes(), $order->fresh()->getAttributes(), $this->invariants($order)]);
    }

    public static function staleStatuses(): array
    {
        return [['PENDING'], ['DECLINED']];
    }

    public function test_existing_reconciliation_timestamp_and_reason_are_not_reset(): void
    {
        [$payment, $order] = $this->fixture();
        $payment->update(['reconciliation_required_at' => now()->subDay(), 'reconciliation_reason' => 'TRANSACTION_CONFLICT']);
        $time = $payment->fresh()->reconciliation_required_at->toIso8601String();
        $order->update(['status' => 'cancelled']);
        $event = $this->event($payment, 'APPROVED');
        $this->fake($event);
        $this->postJson('/api/webhooks/wompi', $event)->assertOk()->assertJsonPath('status', 'requires_reconciliation');
        $this->assertSame($time, $payment->fresh()->reconciliation_required_at->toIso8601String());
        $this->assertSame('TRANSACTION_CONFLICT', $payment->fresh()->reconciliation_reason);
    }

    public function test_previously_deferred_failed_event_is_reverified_and_reconciled(): void
    {
        [$payment, $order] = $this->fixture();
        $order->update(['status' => 'cancelled']);
        $event = $this->event($payment, 'APPROVED');
        $ledger = $this->verified($event);
        $ledger->update(['status' => 'failed', 'last_error' => 'WOMPI_TRANSITION_OUT_OF_SCOPE', 'metadata' => ['environment' => 'test', 'retryable' => false]]);
        $this->fake($event);
        $this->postJson('/api/webhooks/wompi', $event)->assertOk()->assertJsonPath('status', 'requires_reconciliation');
        $this->assertDatabaseCount('webhook_events', 1);
        $this->assertNull($ledger->fresh()->last_error);
        Http::assertSentCount(1);
    }

    public function test_notification_failure_rolls_back_financial_flags_and_allows_retry(): void
    {
        [$payment, $order] = $this->fixture();
        $this->complete($payment, 'tx-normal');
        User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $before = [$payment->fresh()->getAttributes(), $order->fresh()->getAttributes(), $this->invariants($order)];
        $fail = true;
        CrmNotification::creating(function ($notification) use (&$fail) {
            if ($fail && $notification->type === CrmNotification::TYPE_PAYMENT_RECONCILIATION_REQUIRED) {
                $fail = false;
                throw new RuntimeException('Simulated alert failure');
            }
        });
        $event = $this->event($payment, 'VOIDED');
        $this->fake($event);
        $this->withoutExceptionHandling();
        try {
            $this->postJson('/api/webhooks/wompi', $event);
            $this->fail('Expected alert failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated alert failure', $exception->getMessage());
        }
        $this->assertSame($before, [$payment->fresh()->getAttributes(), $order->fresh()->getAttributes(), $this->invariants($order)]);
        $this->assertSame('received', WebhookEvent::sole()->status);
        $this->assertNull(WebhookEvent::sole()->processed_at);
        $this->assertDatabaseCount('crm_notifications', 0);
        $this->postJson('/api/webhooks/wompi', $event)->assertOk()->assertJsonPath('status', 'requires_reconciliation');
        $this->assertDatabaseCount('crm_notifications', 1);
        $this->assertSame('unpaid', $order->fresh()->payment_status);
    }

    public function test_invalid_provider_match_does_not_mark_reconciliation(): void
    {
        [$payment, $order] = $this->fixture();
        $order->update(['status' => 'cancelled']);
        $event = $this->event($payment, 'APPROVED');
        $provider = $event['data']['transaction'];
        $provider['amount_in_cents']++;
        Http::fake(['*' => Http::response(['data' => $provider])]);
        $this->postJson('/api/webhooks/wompi', $event)->assertOk()->assertJsonPath('status', 'failed');
        $this->assertNull($payment->fresh()->reconciliation_required_at);
        $this->assertNull($payment->fresh()->transaction_id);
        $this->assertDatabaseCount('crm_notifications', 0);
        $review = PaymentReconciliationReview::sole();
        $this->assertSame('WOMPI_EVENT_AMOUNT_IN_CENTS_MISMATCH', $review->reason);
        $this->assertNull($review->payment_id);
    }

    public function test_resolved_review_stays_immutable_and_new_webhook_evidence_creates_successor(): void
    {
        [$payment, $order] = $this->fixture();
        $order->update(['status' => 'cancelled']);
        $event = $this->event($payment, 'APPROVED');
        $newEvent = $event;
        $newEvent['data']['transaction']['id'] = 'tx-new-evidence';
        $newEvent['signature']['checksum'] = hash(
            'sha256',
            'tx-new-evidenceAPPROVED'.$newEvent['data']['transaction']['amount_in_cents'].'1790000000test_events_fixture',
        );
        Http::fake(['*' => Http::sequence()
            ->push(['data' => $event['data']['transaction']])
            ->push(['data' => $newEvent['data']['transaction']])]);
        $this->postJson('/api/webhooks/wompi', $event)->assertOk()->assertJsonPath('status', 'requires_reconciliation');
        $first = PaymentReconciliationReview::sole();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
        $resolutions = app(PaymentReconciliationResolutionService::class);
        $resolutions->begin($first, $admin, 'start-first');
        $resolutions->decide($first, $admin, 'resolve-first', [
            'decision' => 'refund_confirmed_externally',
            'justification' => 'External refund was verified safely.',
            'evidence_reference' => 'case-first',
        ]);
        $history = $first->actions()->orderBy('id')->get()->toJson();
        $this->assertNull($payment->fresh()->reconciliation_required_at);

        $this->postJson('/api/webhooks/wompi', $event)->assertOk()->assertJsonPath('status', 'requires_reconciliation');
        $this->assertDatabaseCount('payment_reconciliation_reviews', 1);
        $this->assertNull($payment->fresh()->reconciliation_required_at);

        $response = $this->postJson('/api/webhooks/wompi', $newEvent)->assertOk();
        $this->assertSame(
            'requires_reconciliation',
            $response->json('status'),
            (string) WebhookEvent::query()->latest('id')->value('last_error'),
        );
        $successor = PaymentReconciliationReview::query()->whereKeyNot($first->id)->sole();
        $this->assertSame($first->id, $successor->parent_review_id);
        $this->assertSame('TRANSACTION_CONFLICT', $successor->reason);
        $this->assertSame('resolved', $first->fresh()->state);
        $this->assertSame($history, $first->actions()->orderBy('id')->get()->toJson());
    }

    private function assertTerminal(): void
    {
        $this->assertSame('requires_reconciliation', WebhookEvent::sole()->status);
        $this->assertNotNull(WebhookEvent::sole()->processed_at);
    }

    private function assertReview(Payment $payment, string $reason): void
    {
        $review = PaymentReconciliationReview::sole();
        $this->assertSame($payment->id, $review->payment_id);
        $this->assertSame($payment->order_id, $review->order_id);
        $this->assertSame(WebhookEvent::sole()->id, $review->webhook_event_id);
        $this->assertSame($reason, $review->reason);
        $this->assertSame('detected', $review->state);
        $action = PaymentReconciliationAction::sole();
        $this->assertSame('detected', $action->action);
        $this->assertNull($action->actor_id);
    }

    private function assertRetryBlocked(string $token): void
    {
        $this->call('POST', '/api/checkout/orders/'.$token.'/payments/wompi', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => 'new-retry',
        ], '{}')
            ->assertConflict()->assertJsonPath('code', 'PAYMENT_RECONCILIATION_REQUIRED');
        $this->getJson('/api/checkout/orders/'.$token)->assertOk()->assertJsonPath('data.can_retry_payment', false)
            ->assertJsonMissingPath('data.payments')->assertJsonMissingPath('data.reconciliation_reason');
    }

    private function complete(Payment $payment, string $id): void
    {
        $payment->update(['status' => 'completed', 'provider_status' => 'APPROVED', 'transaction_id' => $id, 'paid_at' => now()]);
        $payment->order->update(['payment_status' => 'paid']);
    }

    private function another(Payment $payment): Payment
    {
        return Payment::create(['order_id' => $payment->order_id, 'amount' => $payment->amount, 'method' => 'wompi', 'provider' => 'wompi',
            'reference' => 'UG79-'.Str::uuid(), 'currency' => 'COP', 'status' => 'pending', 'expires_at' => $payment->expires_at])->fresh();
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

        return [Payment::sole(), $order->fresh(), $token];
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
