<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerVehicle;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\QuotationStatusHistory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportsPhaseOneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-13 15:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function createApplication()
    {
        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        if ($connection = getenv('TEST_DB_CONNECTION')) {
            $app['config']->set('database.default', $connection);
            $app['config']->set("database.connections.{$connection}.database", getenv('TEST_DB_DATABASE') ?: 'upgrade_reports_test');
        }

        return $app;
    }

    public function test_all_endpoints_require_reports_view_and_allow_sales(): void
    {
        $paths = ['sales', 'products', 'payments', 'receivables', 'inventory', 'inventory-movements', 'quotations', 'customers'];
        foreach ($paths as $path) {
            $this->getJson("/api/admin/reports/{$path}")->assertUnauthorized();
        }
        Sanctum::actingAs(User::factory()->create(['role' => 'viewer']));
        foreach ($paths as $path) {
            $this->getJson("/api/admin/reports/{$path}")->assertForbidden();
        }
        Sanctum::actingAs(User::factory()->create(['role' => 'user']));
        foreach ($paths as $path) {
            $this->getJson("/api/admin/reports/{$path}")->assertForbidden();
        }
    }

    public function test_date_validation_and_bogota_semi_open_sales_period(): void
    {
        $this->admin();
        $this->order(Order::STATUS_CONFIRMED, '2026-08-13 04:59:59', 90000);
        $this->order(Order::STATUS_CONFIRMED, '2026-08-13 05:00:00', 100000);
        $this->order(Order::STATUS_COMPLETED, '2026-08-14 04:59:59', 200000);
        $this->order(Order::STATUS_CONFIRMED, '2026-08-14 05:00:00', 300000);

        $this->getJson('/api/admin/reports/sales?date_from=2026-08-13&date_to=2026-08-13')
            ->assertOk()->assertJsonPath('data.period.timezone', 'America/Bogota')
            ->assertJsonPath('data.summary.orders_count', 2)->assertJsonPath('data.summary.sales_total', 300000)
            ->assertJsonPath('data.by_day.0.date', '2026-08-13');
        $this->getJson('/api/admin/reports/sales?date_from=2026-08-14&date_to=2026-08-13')->assertUnprocessable();
        $this->getJson('/api/admin/reports/sales?date_from=2025-01-01&date_to=2026-08-13')->assertUnprocessable();
    }

    public function test_sales_and_products_apply_business_rules_snapshots_and_financial_permission(): void
    {
        $admin = $this->admin();
        $customer = Customer::create(['name' => 'Cliente reportes', 'is_active' => true]);
        $product = $this->product('Pantalla', 'P-1');
        $variant = ProductVariant::create(['product_id' => $product->id, 'name' => '8GB', 'normalized_name' => '8gb', 'sku' => 'V-1', 'price' => 100000, 'total_cost' => 40000, 'is_active' => true]);
        $valid = $this->order(Order::STATUS_CONFIRMED, '2026-08-13 12:00:00', 190000, $customer, $admin, Order::ORIGIN_CRM, 10000);
        $this->item($valid, $product, $variant, 2, 100000, 40000, 10000);
        $unknown = $this->product('Histórico', 'H-1');
        $this->item($valid, $unknown, null, 1, 10000, null, 0);
        $pending = $this->order(Order::STATUS_PENDING, '2026-08-13 12:00:00', 500000);
        $this->item($pending, $product, null, 5, 100000, 40000, 0);

        $this->getJson('/api/admin/reports/sales?date_from=2026-08-13&date_to=2026-08-13&product_variant_id='.$variant->id)
            ->assertOk()->assertJsonPath('data.summary.orders_count', 1)->assertJsonPath('data.summary.average_ticket', 190000)
            ->assertJsonPath('data.financials.known_cost_revenue', 190000)->assertJsonPath('data.financials.cogs', 80000);
        $this->getJson('/api/admin/reports/products?date_from=2026-08-13&date_to=2026-08-13&group_by=sku')
            ->assertOk()->assertJsonPath('data.summary.units_sold', 3)->assertJsonPath('data.summary.distinct_orders', 1)
            ->assertJsonStructure(['data' => ['rows' => ['data' => [['financials']]]]]);

        Sanctum::actingAs(User::factory()->create(['role' => 'user']));
        $this->getJson('/api/admin/reports/sales?date_from=2026-08-13&date_to=2026-08-13')->assertForbidden();
        $this->getJson('/api/admin/reports/products?date_from=2026-08-13&date_to=2026-08-13')->assertForbidden();
    }

    public function test_payments_and_receivables_use_completed_payments_without_net_refund(): void
    {
        $operator = $this->admin();
        $partial = $this->order(Order::STATUS_CONFIRMED, '2026-07-01 12:00:00', 100000, operator: $operator);
        $paid = $this->order(Order::STATUS_COMPLETED, '2026-06-01 12:00:00', 50000, operator: $operator);
        $pending = $this->order(Order::STATUS_PENDING, '2026-08-13 12:00:00', 90000);
        $this->payment($partial, Payment::STATUS_COMPLETED, 40000, '2026-08-13 12:00:00', $operator);
        $this->payment($partial, Payment::STATUS_REFUNDED, 10000, '2026-08-13 13:00:00', $operator);
        $this->payment($partial, Payment::STATUS_FAILED, 10000, '2026-08-13 13:00:00', $operator);
        $this->payment($paid, Payment::STATUS_COMPLETED, 50000, '2026-08-13 12:00:00', $operator);
        $this->payment($pending, Payment::STATUS_COMPLETED, 90000, '2026-08-13 12:00:00', $operator);

        $this->getJson('/api/admin/reports/payments?date_from=2026-08-13&date_to=2026-08-13')
            ->assertOk()->assertJsonPath('data.summary.completed_amount', 180000)->assertJsonPath('data.summary.completed_count', 3);
        $this->getJson('/api/admin/reports/receivables')->assertOk()
            ->assertJsonPath('data.summary.outstanding_total', 60000)->assertJsonPath('data.summary.orders_with_balance', 1)
            ->assertJsonPath('data.rows.data.0.days_outstanding', 43);
    }

    public function test_inventory_reports_distinguish_variants_and_summarize_registered_movements(): void
    {
        $operator = $this->admin();
        $simple = $this->product('Simple bajo', 'S-1');
        $parent = $this->product('Con variantes', 'PARENT');
        $variant = ProductVariant::create(['product_id' => $parent->id, 'name' => 'Agotada', 'normalized_name' => 'agotada', 'sku' => 'OUT-1', 'price' => 1000, 'is_active' => true]);
        ProductVariant::create(['product_id' => $parent->id, 'name' => 'Inactiva', 'normalized_name' => 'inactiva', 'sku' => 'OFF', 'price' => 1000, 'is_active' => false]);
        $branch = Branch::create(['code' => 'QA', 'slug' => 'qa', 'name' => 'QA', 'city' => 'QA', 'is_active' => true]);
        $simpleItem = InventoryItem::create(['product_id' => $simple->id]);
        $variantItem = InventoryItem::create(['product_variant_id' => $variant->id]);
        InventoryStock::create(['branch_id' => $branch->id, 'inventory_item_id' => $simpleItem->id, 'quantity' => 2, 'minimum_quantity' => 3]);
        InventoryStock::create(['branch_id' => $branch->id, 'inventory_item_id' => $variantItem->id, 'quantity' => 0, 'minimum_quantity' => 2]);
        InventoryMovement::create(['product_id' => $simple->id, 'type' => InventoryMovement::TYPE_ENTRY, 'quantity_delta' => 2, 'stock_before' => 0, 'stock_after' => 2, 'created_by' => $operator->id, 'created_at' => '2026-08-13 12:00:00']);
        InventoryMovement::create(['product_id' => $parent->id, 'product_variant_id' => $variant->id, 'type' => InventoryMovement::TYPE_SALE, 'quantity_delta' => -1, 'stock_before' => 1, 'stock_after' => 0, 'created_by' => $operator->id, 'created_at' => '2026-08-13 13:00:00']);

        $this->getJson('/api/admin/reports/inventory?status=out')->assertOk()
            ->assertJsonPath('data.summary.out_count', 1)->assertJsonPath('data.rows.data.0.product_variant_id', $variant->id)
            ->assertJsonPath('data.historical_reconciliation', false);
        $this->getJson('/api/admin/reports/inventory-movements?date_from=2026-08-13&date_to=2026-08-13')
            ->assertOk()->assertJsonPath('data.summary.movements_count', 2)->assertJsonPath('data.summary.units_in', 2)
            ->assertJsonPath('data.summary.units_out', 1)->assertJsonPath('data.historical_reconciliation', false);
    }

    public function test_quotation_report_uses_effective_expiry_and_transition_dates_without_writes(): void
    {
        $operator = $this->admin();
        $expired = $this->quotation(Quotation::STATUS_SENT, '2026-08-12', '2026-08-01 12:00:00', $operator);
        $sent = $this->quotation(Quotation::STATUS_SENT, '2026-08-20', '2026-08-01 12:00:00', $operator);
        QuotationStatusHistory::create(['quotation_id' => $sent->id, 'from_status' => 'draft', 'to_status' => 'sent', 'changed_by' => $operator->id, 'created_at' => '2026-08-13 12:00:00']);
        $rejected = $this->quotation(Quotation::STATUS_REJECTED, '2026-08-20', '2026-08-01 12:00:00', $operator);
        QuotationStatusHistory::create(['quotation_id' => $rejected->id, 'from_status' => 'sent', 'to_status' => 'rejected', 'changed_by' => $operator->id, 'created_at' => '2026-08-13 13:00:00']);
        $converted = $this->quotation(Quotation::STATUS_CONVERTED, '2026-08-20', '2026-08-01 12:00:00', $operator);
        $order = $this->order(Order::STATUS_CONFIRMED, '2026-08-13 12:00:00', 100000);
        $converted->forceFill(['order_id' => $order->id, 'converted_at' => '2026-08-13 14:00:00'])->save();

        $this->getJson('/api/admin/reports/quotations?date_from=2026-08-01&date_to=2026-08-13&status=expired')
            ->assertOk()->assertJsonPath('data.rows.data.0.status', 'expired');
        $this->assertSame(Quotation::STATUS_SENT, $expired->fresh()->status);
        $this->getJson('/api/admin/reports/quotations?date_from=2026-08-13&date_to=2026-08-13')
            ->assertOk()->assertJsonPath('data.summary.sent_in_period', 1)->assertJsonPath('data.summary.rejected_in_period', 1)
            ->assertJsonPath('data.summary.converted_in_period', 1)->assertJsonPath('data.summary.resolved_in_period', 2)
            ->assertJsonPath('data.summary.conversion_rate_resolved', 50);
    }

    public function test_customer_report_counts_only_persisted_buyers_and_omits_pii(): void
    {
        $this->admin();
        $buyer = Customer::create(['name' => 'Comprador', 'phone' => '300123', 'email' => 'private@example.com', 'document' => '999', 'is_active' => true]);
        $other = Customer::create(['name' => 'Sin compras', 'is_active' => false]);
        CustomerVehicle::create(['customer_id' => $buyer->id, 'nickname' => 'Auto', 'is_active' => true]);
        $this->order(Order::STATUS_CONFIRMED, '2026-08-13 12:00:00', 100000, $buyer);
        $this->order(Order::STATUS_COMPLETED, '2026-08-12 12:00:00', 50000, $buyer);
        $this->order(Order::STATUS_CONFIRMED, '2026-08-12 12:00:00', 70000);

        $response = $this->getJson('/api/admin/reports/customers?date_from=2026-08-01&date_to=2026-08-13&buyer=yes&search=private@example.com')->assertOk();
        $response->assertJsonPath('data.summary.registered_total', 2)->assertJsonPath('data.summary.buyers_in_period', 1)
            ->assertJsonPath('data.rows.data.0.orders_count', 2)->assertJsonPath('data.rows.data.0.total_spent', 150000)
            ->assertJsonPath('data.rows.data.0.vehicles_count', 1)->assertJsonMissingPath('data.rows.data.0.email')->assertJsonMissingPath('data.rows.data.0.phone');
        $this->getJson('/api/admin/reports/customers?date_from=2026-08-01&date_to=2026-08-13&buyer=no')->assertOk()->assertJsonPath('data.rows.data.0.customer_id', $other->id);
    }

    private function admin(): User
    {
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function product(string $name, string $sku): Product
    {
        return Product::create(['name' => $name, 'slug' => strtolower(str_replace(' ', '-', $name)).'-'.uniqid(), 'sku' => $sku, 'price' => 100000, 'total_cost' => 40000, 'is_active' => true, 'is_visible' => true]);
    }

    private function order(string $status, string $confirmedAt, int $total, ?Customer $customer = null, ?User $operator = null, string $origin = Order::ORIGIN_CRM, int $discount = 0): Order
    {
        return Order::create(['order_number' => 'R-'.uniqid(), 'origin' => $origin, 'customer_id' => $customer?->id, 'customer_name' => $customer?->name, 'subtotal' => $total + $discount, 'discount_total' => $discount, 'total' => $total, 'status' => $status, 'payment_status' => Order::PAYMENT_UNPAID, 'confirmed_at' => $confirmedAt, 'created_by' => $operator?->id]);
    }

    private function item(Order $order, Product $product, ?ProductVariant $variant, int $quantity, int $unitPrice, ?int $unitCost, int $discount): OrderItem
    {
        return OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'product_variant_id' => $variant?->id, 'product_name' => $product->name, 'product_sku' => $product->sku, 'variant_name' => $variant?->name, 'variant_sku' => $variant?->sku, 'unit_price' => $unitPrice, 'unit_cost' => $unitCost, 'quantity' => $quantity, 'subtotal' => $unitPrice * $quantity, 'discount_amount' => $discount, 'total' => $unitPrice * $quantity - $discount]);
    }

    private function payment(Order $order, string $status, int $amount, string $paidAt, User $operator): Payment
    {
        return Payment::create(['order_id' => $order->id, 'amount' => $amount, 'method' => Payment::METHOD_CASH, 'status' => $status, 'paid_at' => $paidAt, 'created_by' => $operator->id]);
    }

    private function quotation(string $status, string $validUntil, string $createdAt, User $operator): Quotation
    {
        $quotation = Quotation::create(['quotation_number' => 'Q-'.uniqid(), 'status' => $status, 'valid_until' => $validUntil, 'subtotal' => 100000, 'total' => 100000, 'created_by' => $operator->id]);
        $quotation->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        return $quotation;
    }
}
