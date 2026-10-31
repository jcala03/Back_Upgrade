<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CrmNotification;
use App\Models\Employee;
use App\Models\EmployeeCommission;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentReconciliationReview;
use App\Models\Product;
use App\Models\User;
use App\Models\UserCapability;
use App\Services\CommissionService;
use App\Services\OrderService;
use App\Services\PaymentReconciliationResolutionService;
use App\Services\PaymentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CommercialAccess;
use Tests\TestCase;

class CommissionEarningKpiTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('upgrade_test', DB::selectOne('SELECT DATABASE() AS db')->db);
        Http::preventStrayRequests();
        Queue::fake();
    }

    private function fixture(): array
    {
        $tag = 'ck-'.Str::lower(Str::random(10));
        $branch = Branch::create(['code' => substr($tag, -10), 'slug' => $tag, 'name' => 'Sede KPI QA', 'city' => 'Bogotá', 'is_active' => true]);
        $seller = User::factory()->create(['role' => 'user', 'is_active' => true]);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $employee = Employee::create(['name' => 'Vendedor KPI QA', 'job_title' => 'Asesor QA', 'branch_id' => $branch->id, 'user_id' => $seller->id, 'is_active' => true]);
        $product = Product::create(['name' => 'Producto KPI QA', 'slug' => $tag, 'sku' => $tag, 'price' => 100000,
            'commission_enabled' => true, 'commission_amount' => 12000, 'is_active' => true, 'is_visible' => false]);
        $order = Order::create(['order_number' => strtoupper($tag), 'branch_id' => $branch->id, 'sales_employee_id' => $employee->id,
            'created_by' => $admin->id, 'origin' => 'crm', 'status' => 'confirmed', 'payment_status' => 'unpaid',
            'confirmed_at' => now(), 'subtotal' => 100000, 'discount_total' => 0, 'total' => 100000, 'currency' => 'COP']);
        $order->items()->create(['item_type' => 'product', 'product_id' => $product->id, 'product_name' => $product->name,
            'product_sku' => $product->sku, 'quantity' => 1, 'unit_price' => 100000, 'subtotal' => 100000, 'discount_amount' => 0, 'total' => 100000]);
        $commission = app(CommissionService::class)->createPendingForOrder($order)->sole();

        return compact('branch', 'seller', 'admin', 'employee', 'product', 'order', 'commission');
    }

    private function complete(Order $order, User $admin): void
    {
        app(OrderService::class)->transition($order, 'completed', $admin);
    }

    private function pay(Order $order, User $admin, int $amount = 100000): Payment
    {
        return app(PaymentService::class)->register($order, ['amount' => $amount, 'method' => 'cash'], $admin);
    }

    public function test_completion_unpaid_partial_then_full_payment_earns_once_with_frozen_rate_and_seller(): void
    {
        extract($this->fixture());
        $this->complete($order, $admin);
        $this->assertSame('pending', $commission->fresh()->status);
        $this->assertNull($commission->fresh()->earned_at);
        $this->pay($order, $admin, 40000);
        $this->assertSame('partial', $order->fresh()->payment_status);
        $this->assertSame('pending', $commission->fresh()->status);
        $product->update(['commission_amount' => 99999]);
        $this->pay($order, $admin, 60000);
        $earned = $commission->fresh();
        $this->assertSame('earned', $earned->status);
        $this->assertSame(12000, $earned->amount);
        $this->assertSame($employee->id, $earned->employee_id);
        $this->assertNotEquals($seller->id, $order->created_by);
        $this->assertNotNull($earned->earned_at);
        $this->assertCount(0, app(CommissionService::class)->earnForOrder($order));
        $this->complete($order, $admin);
        $this->assertSame($earned->earned_at->toISOString(), $commission->fresh()->earned_at->toISOString());
        $this->assertSame(1, EmployeeCommission::where('order_id', $order->id)->count());
        $this->assertSame(1, CrmNotification::where('dedupe_key', 'commission_earned:'.$commission->id)->count());
    }

    public function test_paid_before_completion_waits_for_operational_completion(): void
    {
        extract($this->fixture());
        $this->pay($order, $admin);
        $this->assertSame('pending', $commission->fresh()->status);
        $this->complete($order, $admin);
        $this->assertSame('earned', $commission->fresh()->status);
    }

    public function test_duplicate_manual_payment_is_rejected_without_rewriting_earned_snapshot(): void
    {
        extract($this->fixture());
        $this->complete($order, $admin);
        $this->pay($order, $admin);
        $before = $commission->fresh()->getAttributes();
        Sanctum::actingAs($admin);
        $this->postJson('/api/admin/orders/'.$order->id.'/payments', ['amount' => 100000, 'method' => 'cash'])->assertUnprocessable();
        $this->assertSame(1, $order->payments()->count());
        $this->assertSame($before, $commission->fresh()->getAttributes());
    }

    public function test_pending_and_voided_events_use_their_own_dates_in_list_and_summary(): void
    {
        extract($this->fixture());
        $commission->forceFill(['created_at' => '2026-08-01 05:00:00'])->save();
        Sanctum::actingAs($seller);
        $query = '?date_basis=event&date_from=2026-08-01&date_to=2026-08-31';
        $this->getJson('/api/my/commissions/summary'.$query)->assertOk()->assertJsonPath('data.totals.pending_amount', 12000);
        $this->getJson('/api/my/commissions'.$query)->assertOk()->assertJsonPath('data.commissions.total', 1);
        $order->update(['status' => 'cancelled']);
        app(CommissionService::class)->voidPendingForOrder($order, $admin, voidedAt: CarbonImmutable::parse('2026-09-01 04:59:59', 'UTC'));
        $this->getJson('/api/my/commissions/summary'.$query)->assertOk()->assertJsonPath('data.totals.pending_amount', 0)->assertJsonPath('data.totals.voided_amount', 12000);
        $this->getJson('/api/my/commissions'.$query)->assertOk()->assertJsonPath('data.commissions.data.0.status', 'voided');
        $commission->update(['voided_at' => '2026-09-01 05:00:00']);
        $this->getJson('/api/my/commissions/summary'.$query)->assertOk()->assertJsonPath('data.totals.total_rows', 0);
        $this->getJson('/api/my/commissions'.$query)->assertOk()->assertJsonPath('data.commissions.total', 0);
    }

    public function test_paid_flag_without_valid_confirmed_credits_never_earns(): void
    {
        extract($this->fixture());
        $order->update(['payment_status' => 'paid', 'status' => 'completed']);
        $service = app(CommissionService::class);
        $this->assertCount(0, $service->earnForOrder($order));
        $payment = $order->payments()->create(['amount' => 100000, 'method' => 'cash', 'status' => 'pending', 'currency' => 'COP']);
        $this->assertCount(0, $service->earnForOrder($order));
        $payment->update(['status' => 'completed', 'currency' => 'USD']);
        $this->assertCount(0, $service->earnForOrder($order));
        $payment->update(['currency' => 'COP', 'reconciliation_required_at' => now(), 'reconciliation_reason' => 'WOMPI_AMOUNT_MISMATCH']);
        $this->assertCount(0, $service->earnForOrder($order));
        $payment->update(['reconciliation_required_at' => null]);
        $review = PaymentReconciliationReview::create(['order_id' => $order->id, 'reason' => 'WOMPI_AMOUNT_MISMATCH',
            'state' => 'detected', 'detection_key' => Str::random(64), 'detected_at' => now()]);
        $this->assertCount(0, $service->earnForOrder($order));
        $review->update(['state' => 'resolved']);
        $payment->update(['status' => 'refunded']);
        $this->assertCount(0, $service->earnForOrder($order));
        $this->assertSame('pending', $commission->fresh()->status);
    }

    public function test_terminal_reconciliation_reevaluates_but_does_not_change_financial_states(): void
    {
        extract($this->fixture());
        $this->complete($order, $admin);
        $payment = $order->payments()->create(['amount' => 100000, 'method' => 'cash', 'status' => 'completed',
            'currency' => 'COP', 'reconciliation_required_at' => now(), 'reconciliation_reason' => 'WOMPI_AMOUNT_MISMATCH']);
        $order->update(['payment_status' => 'paid']);
        $review = PaymentReconciliationReview::create(['order_id' => $order->id, 'payment_id' => $payment->id, 'reason' => 'WOMPI_AMOUNT_MISMATCH',
            'state' => 'detected', 'detection_key' => Str::random(64), 'detected_at' => now()]);
        $this->assertCount(0, app(CommissionService::class)->earnForOrder($order));
        $resolution = app(PaymentReconciliationResolutionService::class);
        $resolution->begin($review, $admin, 'start-'.Str::uuid());
        $key = 'resolve-'.Str::uuid();
        $data = ['decision' => 'invalid_event_confirmed', 'justification' => 'Evento inválido comprobado en QA aislado', 'evidence_reference' => 'QA-NO-REAL-PROVIDER'];
        $resolution->decide($review, $admin, $key, $data);
        $earnedAt = $commission->fresh()->earned_at->toISOString();
        $this->assertSame('earned', $commission->fresh()->status);
        $this->assertSame('completed', $payment->fresh()->status);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertTrue($resolution->decide($review, $admin, $key, $data)['replayed']);
        $this->assertSame($earnedAt, $commission->fresh()->earned_at->toISOString());
    }

    public function test_base_user_permission_and_own_scope_cannot_be_overridden_by_parameters(): void
    {
        extract($this->fixture());
        $other = $this->fixture();
        $this->assertTrue($seller->hasPermission('commissions.view_own'));
        $this->assertFalse($seller->hasPermission('commissions.view'));
        $this->assertSame(0, $seller->capabilities()->count());
        UserCapability::create(['user_id' => $seller->id, 'capability' => UserCapability::QUOTATIONS_SEND_OWN]);
        $seller->unsetRelation('capabilities');
        $this->assertTrue($seller->hasPermission(UserCapability::QUOTATIONS_SEND_OWN));
        Sanctum::actingAs($seller);
        $this->getJson('/api/auth/me')->assertOk()->assertJsonFragment(['commissions.view_own']);
        $this->getJson('/api/my/commissions')->assertOk()->assertJsonPath('data.commissions.total', 1)->assertJsonPath('data.commissions.data.0.id', $commission->id);
        foreach (['employee_id', 'sales_employee_id', 'branch_id', 'user_id'] as $param) {
            $this->getJson('/api/my/commissions/summary?'.$param.'='.$other['employee']->id)->assertUnprocessable();
            $this->getJson('/api/my/commissions?'.$param.'='.$other['employee']->id)->assertUnprocessable();
        }
        $this->getJson('/api/admin/commissions/summary')->assertForbidden();
        $this->getJson('/api/admin/commissions/'.$other['commission']->id)->assertForbidden();
        $this->getJson('/api/my/sales/'.$other['order']->id)->assertNotFound();
        $limited = CommercialAccess::limited($seller, ['orders.view_own']);
        $limited->setRelation('employee', $employee);
        Sanctum::actingAs($limited);
        $this->getJson('/api/my/commissions')->assertForbidden();
    }

    public function test_kpis_and_list_share_event_period_bogota_boundaries_historical_branch_and_distinct_sales(): void
    {
        extract($this->fixture());
        $other = $this->fixture();
        $this->pay($order, $admin, 40000);
        $this->pay($order, $admin, 60000);
        $this->complete($order, $admin);
        $commission->forceFill(['created_at' => '2026-07-01 12:00:00', 'earned_at' => '2026-08-01 05:00:00'])->save();
        $copyItem = $order->items()->first()->replicate();
        $copyItem->save();
        $copy = $commission->fresh()->replicate();
        $copy->order_item_id = $copyItem->id;
        $copy->save();
        $outside = $copy->replicate();
        $thirdItem = $copyItem->replicate();
        $thirdItem->save();
        $outside->order_item_id = $thirdItem->id;
        $outside->earned_at = '2026-08-01 04:59:59';
        $outside->save();
        $employee->update(['branch_id' => $other['branch']->id]);
        Sanctum::actingAs($admin);
        $query = 'branch_id='.$branch->id.'&date_from=2026-08-01&date_to=2026-08-31&date_basis=event';
        $this->getJson('/api/admin/commissions/summary?'.$query)->assertOk()
            ->assertJsonPath('data.totals.earned_amount', 24000)->assertJsonPath('data.totals.sales_count', 1)
            ->assertJsonPath('data.totals.total_rows', 2)->assertJsonPath('data.by_employee.0.employee.id', $employee->id);
        $this->getJson('/api/admin/commissions?'.$query.'&per_page=1')->assertOk()->assertJsonPath('data.commissions.total', 2)->assertJsonCount(1, 'data.commissions.data');
        Sanctum::actingAs($seller);
        $query = 'date_from=2026-08-01&date_to=2026-08-31&date_basis=event';
        $this->getJson('/api/my/commissions/summary?'.$query)->assertOk()->assertJsonPath('data.totals.earned_amount', 24000);
        $this->getJson('/api/my/commissions?'.$query)->assertOk()->assertJsonPath('data.commissions.total', 2);
        $this->getJson('/api/my/commissions/summary?'.$query.'&status=pending')->assertOk()->assertJsonPath('data.totals.total_rows', 0);
    }

    public function test_refund_flags_historical_earnings_without_deleting_or_recalculating_them(): void
    {
        extract($this->fixture());
        $payment = $this->pay($order, $admin);
        $this->complete($order, $admin);
        $before = $commission->fresh()->getAttributes();
        $payment->update(['status' => 'refunded']);
        $order->update(['payment_status' => 'refunded']);
        app(CommissionService::class)->earnForOrder($order);
        $this->assertSame($before, $commission->fresh()->getAttributes());
        Sanctum::actingAs($seller);
        $this->getJson('/api/my/commissions')->assertOk()->assertJsonPath('data.commissions.data.0.requires_review', true);
        $this->getJson('/api/my/commissions/summary')->assertOk()->assertJsonPath('data.totals.earned_amount', 12000)->assertJsonPath('data.totals.review_amount', 12000);
    }

    public function test_real_webhook_duplicate_approval_preserves_earning_time_and_refund_is_flagged(): void
    {
        extract($this->fixture());
        config(['services.wompi' => ['base_url' => 'https://sandbox.wompi.co/v1', 'private_key' => 'prv_test_commission_fixture',
            'public_key' => 'pub_test_commission_fixture', 'events_secret' => 'test_events_commissions']]);
        $order->update(['origin' => 'ecommerce', 'stock_committed_at' => now(), 'stock_reservation_expires_at' => now()->addMinutes(30)]);
        $payment = $order->payments()->create(['amount' => 100000, 'method' => 'wompi', 'provider' => 'wompi',
            'status' => 'pending', 'currency' => 'COP', 'reference' => 'QA-'.Str::uuid()]);
        $transactionId = 'qa-transaction-'.Str::uuid();
        $event = static function (string $status) use ($payment, $transactionId): array {
            $timestamp = now()->timestamp;
            $transaction = ['id' => $transactionId, 'reference' => $payment->reference, 'status' => $status,
                'amount_in_cents' => 10000000, 'currency' => 'COP'];

            return ['event' => 'transaction.updated', 'environment' => 'test', 'timestamp' => $timestamp,
                'data' => ['transaction' => $transaction], 'signature' => [
                    'properties' => ['transaction.id', 'transaction.status', 'transaction.amount_in_cents'],
                    'checksum' => hash('sha256', $transactionId.$status.'10000000'.$timestamp.'test_events_commissions')]];
        };
        $approved = $event('APPROVED');
        $void = $event('VOIDED');
        Http::fake(['*' => Http::sequence()->push(['data' => $approved['data']['transaction']])->push(['data' => $void['data']['transaction']])]);
        $this->postJson('/api/webhooks/wompi', $approved)->assertOk();
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('pending', $commission->fresh()->status);
        $this->complete($order, $admin);
        $earned = $commission->fresh()->getAttributes();
        $this->postJson('/api/webhooks/wompi', $approved)->assertOk();
        $this->assertSame($earned, $commission->fresh()->getAttributes());
        $this->postJson('/api/webhooks/wompi', $void)->assertOk();
        $this->assertSame('refunded', $payment->fresh()->status);
        $this->assertSame($earned, $commission->fresh()->getAttributes());
        Sanctum::actingAs($seller);
        $this->getJson('/api/my/commissions')->assertOk()->assertJsonPath('data.commissions.data.0.requires_review', true);
    }
}
