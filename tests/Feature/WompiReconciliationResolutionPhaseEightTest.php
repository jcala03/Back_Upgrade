<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentReconciliationAction;
use App\Models\PaymentReconciliationReview;
use App\Models\User;
use App\Models\UserCapability;
use App\Models\WebhookEvent;
use App\Services\PaymentReconciliationResolutionService;
use App\Services\WompiPaymentReconciliationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class WompiReconciliationResolutionPhaseEightTest extends TestCase
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

    public function test_legacy_backfill_preserves_reason_timestamp_and_financial_state_without_guessing_event(): void
    {
        [$payment, $order] = $this->payment();
        $detectedAt = now()->subDay()->startOfSecond();
        $payment->update([
            'reconciliation_required_at' => $detectedAt,
            'reconciliation_reason' => WompiPaymentReconciliationService::TRANSACTION_CONFLICT,
        ]);
        $before = [$this->financialPayment($payment), $order->fresh()->getAttributes(), $this->operationalSnapshot()];

        $this->service()->backfillLegacyMarkers();
        $this->service()->backfillLegacyMarkers();

        $review = PaymentReconciliationReview::sole();
        $this->assertSame($payment->id, $review->payment_id);
        $this->assertSame($order->id, $review->order_id);
        $this->assertNull($review->webhook_event_id);
        $this->assertSame('TRANSACTION_CONFLICT', $review->reason);
        $this->assertSame('detected', $review->state);
        $this->assertTrue($review->detected_at->equalTo($detectedAt));
        $action = PaymentReconciliationAction::sole();
        $this->assertSame('detected', $action->action);
        $this->assertNull($action->actor_id);
        $this->assertSame($before, [$this->financialPayment($payment->fresh()), $order->fresh()->getAttributes(), $this->operationalSnapshot()]);
    }

    public function test_authorization_list_show_start_and_no_manual_reopen(): void
    {
        [$payment] = $this->payment();
        $review = $this->detect(WompiPaymentReconciliationService::LATE_APPROVAL, $payment);
        $admin = $this->admin();
        $collaborator = User::factory()->create(['role' => User::ROLE_USER, 'is_active' => true]);
        UserCapability::create([
            'user_id' => $collaborator->id,
            'capability' => UserCapability::PAYMENTS_CREATE_OWN,
            'granted_by' => $admin->id,
        ]);

        $this->getJson('/api/admin/payment-reconciliations')->assertUnauthorized();
        Sanctum::actingAs($collaborator);
        $this->getJson('/api/admin/payment-reconciliations')->assertForbidden();
        $this->postJson("/api/admin/payment-reconciliations/{$review->id}/start", [], ['Idempotency-Key' => 'denied'])->assertForbidden();
        $this->assertFalse($collaborator->hasPermission('payments.reconcile'));
        $this->assertNotContains('payments.reconcile', UserCapability::allowed());

        Sanctum::actingAs($admin);
        $this->getJson('/api/admin/payment-reconciliations?state=detected&payment_id='.$payment->id)
            ->assertOk()->assertJsonPath('data.data.0.id', $review->id);
        $this->getJson("/api/admin/payment-reconciliations/{$review->id}")
            ->assertOk()->assertJsonPath('data.reason', 'LATE_APPROVAL')
            ->assertJsonMissingPath('data.detection_key')
            ->assertJsonMissingPath('data.actions.0.idempotency_key')
            ->assertJsonMissingPath('data.webhook_event.payload_hash');
        $this->postJson("/api/admin/payment-reconciliations/{$review->id}/reopen", [], ['Idempotency-Key' => 'none'])->assertNotFound();
    }

    public function test_state_machine_idempotency_conflicts_and_stale_competing_resolution(): void
    {
        [$payment] = $this->payment();
        $detected = $this->detect(WompiPaymentReconciliationService::LATE_APPROVAL, $payment);
        $admin = $this->admin();
        $otherAdmin = $this->admin();
        Sanctum::actingAs($admin);

        $terminal = ['decision' => 'refund_confirmed_externally', 'justification' => 'Refund verified externally.', 'evidence_reference' => 'case-123'];
        $this->postJson("/api/admin/payment-reconciliations/{$detected->id}/decisions", $terminal, ['Idempotency-Key' => 'direct'])
            ->assertConflict()->assertJsonPath('code', 'RECONCILIATION_STATE_CONFLICT');

        $this->postJson("/api/admin/payment-reconciliations/{$detected->id}/start", [], ['Idempotency-Key' => 'start-1'])
            ->assertCreated()->assertJsonPath('data.state', 'under_review')->assertJsonPath('replayed', false);
        $this->postJson("/api/admin/payment-reconciliations/{$detected->id}/start", [], ['Idempotency-Key' => 'start-1'])
            ->assertOk()->assertJsonPath('replayed', true);

        Sanctum::actingAs($otherAdmin);
        $this->postJson("/api/admin/payment-reconciliations/{$detected->id}/start", [], ['Idempotency-Key' => 'start-1'])
            ->assertConflict()->assertJsonPath('code', 'RECONCILIATION_IDEMPOTENCY_CONFLICT');
        Sanctum::actingAs($admin);

        $nonTerminal = ['decision' => 'refund_required', 'justification' => 'External refund must be completed.'];
        $this->postJson("/api/admin/payment-reconciliations/{$detected->id}/decisions", $nonTerminal, ['Idempotency-Key' => 'decision-1'])
            ->assertCreated()->assertJsonPath('data.state', 'under_review')->assertJsonPath('action.previous_state', 'under_review')
            ->assertJsonPath('action.new_state', 'under_review');
        $this->postJson("/api/admin/payment-reconciliations/{$detected->id}/decisions", $nonTerminal, ['Idempotency-Key' => 'decision-1'])
            ->assertOk()->assertJsonPath('replayed', true);
        $changed = [...$nonTerminal, 'justification' => 'A different refund justification.'];
        $this->postJson("/api/admin/payment-reconciliations/{$detected->id}/decisions", $changed, ['Idempotency-Key' => 'decision-1'])
            ->assertConflict()->assertJsonPath('code', 'RECONCILIATION_IDEMPOTENCY_CONFLICT');

        $before = [$this->financialPayment($payment->fresh()), $payment->order->fresh()->getAttributes(), $this->operationalSnapshot()];
        $this->postJson("/api/admin/payment-reconciliations/{$detected->id}/decisions", $terminal, ['Idempotency-Key' => 'terminal-1'])
            ->assertCreated()->assertJsonPath('data.state', 'resolved')->assertJsonPath('action.action', 'resolved');
        $this->postJson("/api/admin/payment-reconciliations/{$detected->id}/decisions", $terminal, ['Idempotency-Key' => 'terminal-2'])
            ->assertConflict()->assertJsonPath('code', 'RECONCILIATION_STATE_CONFLICT');
        $this->assertSame($before, [$this->financialPayment($payment->fresh()), $payment->order->fresh()->getAttributes(), $this->operationalSnapshot()]);
        $this->assertNull($payment->fresh()->reconciliation_required_at);
        $this->assertSame(4, $detected->actions()->count());
        $this->assertSame(1, $detected->actions()->where('action', 'resolved')->count());
    }

    #[DataProvider('decisionMatrix')]
    public function test_reason_decision_matrix_accepts_valid_and_rejects_invalid(
        string $reason,
        string $valid,
        string $invalid,
        bool $withPayment,
        bool $terminal,
    ): void {
        [$payment] = $this->payment();
        $review = $this->detect($reason, $withPayment ? $payment : null);
        $admin = $this->admin();
        $this->service()->begin($review, $admin, 'start-'.$reason);
        $canonical = null;
        if ($reason === WompiPaymentReconciliationService::DUPLICATE_APPROVAL) {
            $canonical = Payment::create([
                'order_id' => $payment->order_id, 'amount' => $payment->amount, 'method' => 'wompi',
                'provider' => 'wompi', 'reference' => 'canonical-'.Str::uuid(), 'status' => 'completed',
            ]);
        }
        Sanctum::actingAs($admin);
        $base = ['justification' => 'The discrepancy was investigated safely.', 'evidence_reference' => 'evidence-123'];
        if ($canonical) {
            $base['canonical_payment_id'] = $canonical->id;
        }
        $this->postJson("/api/admin/payment-reconciliations/{$review->id}/decisions", ['decision' => $invalid, ...$base], ['Idempotency-Key' => 'invalid-'.$reason])
            ->assertUnprocessable()->assertJsonValidationErrors('decision');
        $response = $this->postJson("/api/admin/payment-reconciliations/{$review->id}/decisions", ['decision' => $valid, ...$base], ['Idempotency-Key' => 'valid-'.$reason])
            ->assertCreated();
        $response->assertJsonPath('data.state', $terminal ? 'resolved' : 'under_review');
        $this->assertSame($valid, $review->fresh()->latest_decision);
        $this->assertSame($canonical?->id, PaymentReconciliationAction::latest('id')->first()->canonical_payment_id);
    }

    public static function decisionMatrix(): array
    {
        return [
            ['LATE_APPROVAL', 'refund_required', 'provider_void_verified', true, false],
            ['DUPLICATE_APPROVAL', 'refund_required', 'provider_void_verified', true, false],
            ['VOID_AFTER_APPROVAL', 'provider_void_verified', 'refund_required', true, true],
            ['TRANSACTION_CONFLICT', 'conflict_ownership_verified', 'refund_required', true, true],
            ['WOMPI_AMOUNT_MISMATCH', 'invalid_event_confirmed', 'refund_required', true, true],
            ['WOMPI_CURRENCY_MISMATCH', 'invalid_event_confirmed', 'refund_required', true, true],
            ['WOMPI_REFERENCE_MISMATCH', 'invalid_event_confirmed', 'refund_required', false, true],
            ['WOMPI_ORDER_MISMATCH', 'invalid_event_confirmed', 'refund_required', true, true],
            ['WOMPI_PAYMENT_NOT_FOUND', 'local_payment_absence_confirmed', 'refund_required', false, true],
            ['WOMPI_TRANSACTION_NOT_FOUND', 'provider_transaction_absence_confirmed', 'invalid_event_confirmed', false, true],
            ['WOMPI_EVENT_AMOUNT_IN_CENTS_MISMATCH', 'invalid_event_confirmed', 'refund_required', false, true],
        ];
    }

    public function test_duplicate_requires_exact_canonical_payment_and_other_reasons_reject_it(): void
    {
        [$payment] = $this->payment();
        [$otherOrderPayment] = $this->payment();
        $admin = $this->admin();
        $review = $this->detect(WompiPaymentReconciliationService::DUPLICATE_APPROVAL, $payment);
        $this->service()->begin($review, $admin, 'start-duplicate');
        Sanctum::actingAs($admin);
        $body = ['decision' => 'refund_required', 'justification' => 'Duplicate payment requires a refund.'];
        $this->postJson("/api/admin/payment-reconciliations/{$review->id}/decisions", $body, ['Idempotency-Key' => 'missing'])
            ->assertUnprocessable()->assertJsonValidationErrors('canonical_payment_id');
        $this->postJson("/api/admin/payment-reconciliations/{$review->id}/decisions", [...$body, 'canonical_payment_id' => $payment->id], ['Idempotency-Key' => 'same'])
            ->assertUnprocessable()->assertJsonValidationErrors('canonical_payment_id');
        $this->postJson("/api/admin/payment-reconciliations/{$review->id}/decisions", [...$body, 'canonical_payment_id' => $otherOrderPayment->id], ['Idempotency-Key' => 'other'])
            ->assertUnprocessable()->assertJsonValidationErrors('canonical_payment_id');

        $late = $this->detect(WompiPaymentReconciliationService::LATE_APPROVAL, $payment, $this->event());
        $this->service()->begin($late, $admin, 'start-late');
        $this->postJson("/api/admin/payment-reconciliations/{$late->id}/decisions", [...$body, 'canonical_payment_id' => $otherOrderPayment->id], ['Idempotency-Key' => 'forbidden'])
            ->assertUnprocessable()->assertJsonValidationErrors('canonical_payment_id');
    }

    public function test_detection_dedupe_successor_parent_and_deterministic_legacy_marker(): void
    {
        [$payment] = $this->payment();
        $event = $this->event();
        $first = $this->detect(WompiPaymentReconciliationService::LATE_APPROVAL, $payment, $event);
        $same = $this->detect(WompiPaymentReconciliationService::LATE_APPROVAL, $payment, $event);
        $this->assertTrue($first->is($same));
        $this->assertDatabaseCount('payment_reconciliation_reviews', 1);
        $this->assertDatabaseCount('payment_reconciliation_actions', 1);

        $admin = $this->admin();
        $this->service()->begin($first, $admin, 'start-first');
        $this->service()->decide($first, $admin, 'resolve-first', [
            'decision' => 'refund_confirmed_externally',
            'justification' => 'External refund was verified safely.',
            'evidence_reference' => 'case-first',
        ]);
        $history = $first->actions()->orderBy('id')->get()->toJson();
        $this->assertNull($payment->fresh()->reconciliation_required_at);

        $this->travel(1)->minute();
        $successor = $this->detect(WompiPaymentReconciliationService::TRANSACTION_CONFLICT, $payment, $this->event());
        $this->assertNotSame($first->id, $successor->id);
        $this->assertSame($first->id, $successor->parent_review_id);
        $this->assertSame($history, $first->actions()->orderBy('id')->get()->toJson());
        $this->assertSame('resolved', $first->fresh()->state);
        $this->assertSame('TRANSACTION_CONFLICT', $payment->fresh()->reconciliation_reason);

        $this->travel(1)->minute();
        $third = $this->detect(WompiPaymentReconciliationService::LATE_APPROVAL, $payment, $this->event());
        $this->assertSame('TRANSACTION_CONFLICT', $payment->fresh()->reconciliation_reason);
        $this->service()->begin($successor, $admin, 'start-second');
        $this->service()->decide($successor, $admin, 'resolve-second', [
            'decision' => 'conflict_ownership_verified',
            'justification' => 'Transaction ownership was verified safely.',
            'evidence_reference' => 'case-second',
        ]);
        $this->assertSame($third->id, PaymentReconciliationReview::query()
            ->where('payment_id', $payment->id)->where('state', '!=', 'resolved')->sole()->id);
        $this->assertSame('LATE_APPROVAL', $payment->fresh()->reconciliation_reason);
    }

    public function test_actions_are_append_only_and_resolution_rejects_financial_fields(): void
    {
        [$payment] = $this->payment();
        $review = $this->detect(WompiPaymentReconciliationService::LATE_APPROVAL, $payment);
        $action = $review->actions()->sole();
        try {
            $action->update(['new_state' => 'resolved']);
            $this->fail('Expected append-only update protection.');
        } catch (LogicException $exception) {
            $this->assertSame('Reconciliation actions are append-only.', $exception->getMessage());
        }

        $admin = $this->admin();
        $this->service()->begin($review, $admin, 'start-immutable');
        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/payment-reconciliations/{$review->id}/decisions", [
            'decision' => 'refund_required',
            'justification' => 'Refund must be handled externally.',
            'amount' => 1,
            'status' => 'completed',
        ], ['Idempotency-Key' => 'financial-fields'])->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame(1000, $payment->fresh()->amount);
    }

    private function payment(): array
    {
        $order = Order::create([
            'order_number' => 'REC-'.Str::uuid(),
            'origin' => Order::ORIGIN_ECOMMERCE,
            'subtotal' => 1000,
            'discount_total' => 0,
            'total' => 1000,
            'status' => Order::STATUS_CONFIRMED,
            'payment_status' => Order::PAYMENT_UNPAID,
        ]);
        $payment = Payment::create([
            'order_id' => $order->id,
            'amount' => 1000,
            'method' => Payment::METHOD_WOMPI,
            'provider' => Payment::PROVIDER_WOMPI,
            'reference' => 'REF-'.Str::uuid(),
            'currency' => 'COP',
            'status' => Payment::STATUS_PENDING,
        ]);

        return [$payment, $order];
    }

    private function event(): WebhookEvent
    {
        $key = hash('sha256', (string) Str::uuid());

        return WebhookEvent::create([
            'provider' => Payment::PROVIDER_WOMPI,
            'event_key' => $key,
            'event_type' => 'transaction.updated',
            'provider_transaction_id' => 'tx-'.Str::uuid(),
            'payload_hash' => hash('sha256', 'payload-'.$key),
            'status' => WebhookEvent::STATUS_REQUIRES_RECONCILIATION,
            'received_at' => now(),
            'processed_at' => now(),
            'metadata' => ['environment' => 'test', 'provider_status' => 'APPROVED'],
        ]);
    }

    private function detect(string $reason, ?Payment $payment, ?WebhookEvent $event = null): PaymentReconciliationReview
    {
        return $this->service()->detect($reason, $event ?? $this->event(), $payment, $payment?->order);
    }

    private function service(): PaymentReconciliationResolutionService
    {
        return app(PaymentReconciliationResolutionService::class);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
    }

    private function financialPayment(Payment $payment): array
    {
        return $payment->fresh()->only([
            'order_id', 'amount', 'method', 'status', 'provider', 'reference', 'transaction_id',
            'paid_at', 'currency', 'provider_status', 'failure_code', 'failure_reason',
        ]);
    }

    private function operationalSnapshot(): array
    {
        $snapshot = [];
        foreach (['inventory_stocks', 'inventory_movements', 'employee_commissions', 'order_status_histories'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $snapshot;
    }
}
