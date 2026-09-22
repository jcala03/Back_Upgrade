<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class AnalyticsMultibranchPhaseFiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-24 15:00:00', 'UTC'));
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
        $database = trim((string) getenv('TEST_DB_DATABASE'));
        if (getenv('TEST_DB_CONNECTION') !== 'mysql' || $database === '' || $database === 'upgrade') {
            throw new RuntimeException('AnalyticsMultibranchPhaseFiveTest requiere MySQL temporal explícita.');
        }
        $app['config']->set('database.default', 'mysql');
        $app['config']->set('database.connections.mysql.database', $database);

        return $app;
    }

    public function test_dashboard_general_branch_and_compare_use_historical_branch_sources(): void
    {
        $admin = $this->admin();
        [$baq, $bog] = $this->branches();
        $customer = Customer::create(['name' => 'Cliente compartido', 'is_active' => true]);
        $product = $this->product('Producto multisede', '=FORMULA', 999);
        $item = InventoryItem::create(['product_id' => $product->id]);
        InventoryStock::create(['branch_id' => $baq->id, 'inventory_item_id' => $item->id, 'quantity' => 1, 'minimum_quantity' => 3]);
        InventoryStock::create(['branch_id' => $bog->id, 'inventory_item_id' => $item->id, 'quantity' => 9, 'minimum_quantity' => 2]);

        $baqOrder = $this->order($baq, Order::STATUS_CONFIRMED, 100000, $customer, $admin);
        $bogOrder = $this->order($bog, Order::STATUS_COMPLETED, 300000, $customer, $admin);
        $pending = $this->order($baq, Order::STATUS_PENDING, 900000, $customer, $admin);
        $cancelled = $this->order($bog, Order::STATUS_CANCELLED, 900000, $customer, $admin);
        $this->item($baqOrder, $product, 2, 50000);
        $this->item($bogOrder, $product, 3, 100000);
        $this->serviceItem($baqOrder, 5);
        $this->item($pending, $product, 9, 100000);
        $this->item($cancelled, $product, 9, 100000);
        $this->payment($baqOrder, 40000);
        $this->payment($bogOrder, 300000);
        $this->quotation($baq, Quotation::STATUS_SENT, '2026-08-25');
        $this->quotation($bog, Quotation::STATUS_DRAFT, '2026-08-26');

        $general = $this->getJson('/api/admin/dashboard')->assertOk();
        $general->assertJsonPath('data.context.mode', 'general')
            ->assertJsonPath('data.sales.month.total', 400000)
            ->assertJsonPath('data.sales.month.orders_count', 2)
            ->assertJsonPath('data.sales.month.average_ticket', 200000)
            ->assertJsonPath('data.sales.product_units_month', 5)
            ->assertJsonPath('data.payments.received_month', 340000)
            ->assertJsonPath('data.payments.outstanding', 60000)
            ->assertJsonPath('data.customers.customers_served_month', 1)
            ->assertJsonPath('data.inventory.low_stock_count', 1)
            ->assertJsonPath('data.inventory.total_quantity', 10)
            ->assertJsonPath('data.low_stock.0.branch.code', 'BAQ');

        $this->getJson('/api/admin/dashboard?branch_id='.$baq->id)->assertOk()
            ->assertJsonPath('data.context.branch.code', 'BAQ')
            ->assertJsonPath('data.sales.month.total', 100000)
            ->assertJsonPath('data.sales.product_units_month', 2)
            ->assertJsonPath('data.payments.received_month', 40000)
            ->assertJsonPath('data.payments.outstanding', 60000)
            ->assertJsonPath('data.inventory.total_quantity', 1)
            ->assertJsonCount(1, 'data.recent_orders')
            ->assertJsonPath('data.recent_orders.0.branch.code', 'BAQ')
            ->assertJsonCount(1, 'data.expiring_quotations');

        $compare = $this->getJson('/api/admin/dashboard/compare?branch_ids[]='.$baq->id.'&branch_ids[]='.$bog->id)->assertOk();
        $compare->assertJsonPath('data.period.timezone', 'America/Bogota')
            ->assertJsonPath('data.branches.0.sales_total', 100000)
            ->assertJsonPath('data.branches.0.average_ticket', 100000)
            ->assertJsonPath('data.branches.0.product_units', 2)
            ->assertJsonPath('data.branches.0.customers_served', 1)
            ->assertJsonPath('data.branches.0.low_stock_positions', 1)
            ->assertJsonPath('data.branches.1.sales_total', 300000)
            ->assertJsonPath('data.branches.1.low_stock_positions', 0);
        $this->getJson('/api/admin/dashboard?branch_id=999999')->assertUnprocessable();
        $this->getJson('/api/admin/dashboard/compare?branch_ids[]='.$baq->id)->assertUnprocessable();
        $this->getJson('/api/admin/dashboard/compare?branch_ids[]='.$baq->id.'&branch_ids[]='.$baq->id)->assertUnprocessable();
    }

    public function test_inactive_branch_history_is_readable_and_zero_compare_is_safe(): void
    {
        $this->admin();
        [$baq, $bog] = $this->branches();
        $bog->update(['is_active' => false]);

        $this->getJson('/api/admin/dashboard?branch_id='.$bog->id)->assertOk()
            ->assertJsonPath('data.context.branch.code', 'BOG')
            ->assertJsonPath('data.sales.month.total', 0)
            ->assertJsonPath('data.inventory.total_quantity', 0);
        $this->getJson('/api/admin/dashboard/compare?branch_ids[]='.$baq->id.'&branch_ids[]='.$bog->id)->assertOk()
            ->assertJsonPath('data.branches.0.average_ticket', 0)
            ->assertJsonPath('data.branches.1.average_ticket', 0)
            ->assertJsonPath('data.branches.1.payments_received', 0);
    }

    public function test_all_eight_reports_apply_branch_scope_without_cross_branch_leakage(): void
    {
        $admin = $this->admin();
        [$baq, $bog] = $this->branches();
        $baqCustomer = Customer::create(['name' => 'Cliente BAQ', 'is_active' => true]);
        $bogCustomer = Customer::create(['name' => 'Cliente BOG', 'is_active' => true]);
        $product = $this->product('Producto reportes', 'REP-1');
        $inventoryItem = InventoryItem::create(['product_id' => $product->id]);
        InventoryStock::create(['branch_id' => $baq->id, 'inventory_item_id' => $inventoryItem->id, 'quantity' => 2, 'minimum_quantity' => 3]);
        InventoryStock::create(['branch_id' => $bog->id, 'inventory_item_id' => $inventoryItem->id, 'quantity' => 8, 'minimum_quantity' => 2]);
        $baqOrder = $this->order($baq, Order::STATUS_CONFIRMED, 100000, $baqCustomer, $admin);
        $bogOrder = $this->order($bog, Order::STATUS_COMPLETED, 200000, $bogCustomer, $admin);
        $this->item($baqOrder, $product, 1, 100000);
        $this->item($bogOrder, $product, 2, 100000);
        $this->payment($baqOrder, 25000);
        $this->payment($bogOrder, 200000);
        $this->quotation($baq, Quotation::STATUS_SENT, '2026-08-25');
        $this->quotation($bog, Quotation::STATUS_DRAFT, '2026-08-26');
        InventoryMovement::create(['branch_id' => $baq->id, 'inventory_item_id' => $inventoryItem->id, 'product_id' => $product->id, 'type' => InventoryMovement::TYPE_ENTRY, 'quantity_delta' => 2, 'stock_before' => 0, 'stock_after' => 2, 'created_by' => $admin->id, 'created_at' => '2026-08-24 12:00:00']);
        InventoryMovement::create(['branch_id' => $bog->id, 'inventory_item_id' => $inventoryItem->id, 'product_id' => $product->id, 'type' => InventoryMovement::TYPE_ENTRY, 'quantity_delta' => 8, 'stock_before' => 0, 'stock_after' => 8, 'created_by' => $admin->id, 'created_at' => '2026-08-24 12:00:00']);
        $period = 'date_from=2026-08-01&date_to=2026-08-24&branch_id='.$baq->id;

        $this->getJson('/api/admin/reports/sales?'.$period)->assertOk()->assertJsonPath('data.summary.sales_total', 100000)->assertJsonCount(1, 'data.rows.data')->assertJsonPath('data.rows.data.0.branch.code', 'BAQ');
        $this->getJson('/api/admin/reports/products?'.$period)->assertOk()->assertJsonPath('data.summary.units_sold', 1)->assertJsonPath('data.rows.data.0.stock', 2);
        $this->getJson('/api/admin/reports/payments?'.$period)->assertOk()->assertJsonPath('data.summary.completed_amount', 25000)->assertJsonCount(1, 'data.rows.data');
        $this->getJson('/api/admin/reports/receivables?'.$period)->assertOk()->assertJsonPath('data.summary.outstanding_total', 75000)->assertJsonCount(1, 'data.rows.data');
        $this->getJson('/api/admin/reports/inventory?branch_id='.$baq->id)->assertOk()->assertJsonPath('data.summary.total_quantity', 2)->assertJsonPath('data.rows.data.0.branch.code', 'BAQ');
        $this->getJson('/api/admin/reports/inventory-movements?'.$period)->assertOk()->assertJsonPath('data.summary.units_in', 2)->assertJsonCount(1, 'data.rows.data');
        $this->getJson('/api/admin/reports/quotations?'.$period)->assertOk()->assertJsonPath('data.summary.created_in_period', 1)->assertJsonCount(1, 'data.rows.data');
        $this->getJson('/api/admin/reports/customers?'.$period)->assertOk()->assertJsonPath('data.summary.registered_total', 2)->assertJsonPath('data.summary.customers_served', 1)->assertJsonCount(1, 'data.rows.data')->assertJsonPath('data.rows.data.0.name', 'Cliente BAQ');

        $this->getJson('/api/admin/reports/sales?date_from=2026-08-01&date_to=2026-08-24')->assertOk()->assertJsonPath('data.summary.sales_total', 300000);
        $this->getJson('/api/admin/reports/inventory')->assertOk()->assertJsonPath('data.summary.total_quantity', 10)->assertJsonPath('data.summary.low_count', 1);
    }

    private function admin(): User
    {
        $user = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function branches(): array
    {
        return [
            Branch::create(['code' => 'BAQ', 'slug' => 'barranquilla', 'name' => 'Barranquilla', 'city' => 'Barranquilla', 'is_active' => true]),
            Branch::create(['code' => 'BOG', 'slug' => 'bogota', 'name' => 'Bogotá', 'city' => 'Bogotá', 'is_active' => true]),
        ];
    }

    private function product(string $name, string $sku): Product
    {
        return Product::create(['name' => $name, 'slug' => 'product-'.uniqid(), 'sku' => $sku, 'price' => 100000, 'total_cost' => 40000, 'is_active' => true, 'is_visible' => true]);
    }

    private function order(Branch $branch, string $status, int $total, Customer $customer, User $operator): Order
    {
        return Order::create(['order_number' => $branch->code.'-'.uniqid(), 'branch_id' => $branch->id, 'origin' => Order::ORIGIN_CRM, 'customer_id' => $customer->id, 'customer_name' => $customer->name, 'subtotal' => $total, 'discount_total' => 0, 'total' => $total, 'status' => $status, 'payment_status' => Order::PAYMENT_UNPAID, 'confirmed_at' => '2026-08-24 12:00:00', 'created_by' => $operator->id]);
    }

    private function item(Order $order, Product $product, int $quantity, int $price): void
    {
        OrderItem::create(['order_id' => $order->id, 'item_type' => OrderItem::ITEM_TYPE_PRODUCT, 'product_id' => $product->id, 'product_name' => $product->name, 'product_sku' => $product->sku, 'unit_price' => $price, 'unit_cost' => 40000, 'quantity' => $quantity, 'subtotal' => $price * $quantity, 'discount_amount' => 0, 'total' => $price * $quantity]);
    }

    private function serviceItem(Order $order, int $quantity): void
    {
        OrderItem::create(['order_id' => $order->id, 'item_type' => OrderItem::ITEM_TYPE_SERVICE, 'service_name' => 'Instalación', 'unit_price' => 0, 'quantity' => $quantity, 'subtotal' => 0, 'discount_amount' => 0, 'total' => 0]);
    }

    private function payment(Order $order, int $amount): void
    {
        Payment::create(['order_id' => $order->id, 'amount' => $amount, 'method' => Payment::METHOD_CASH, 'status' => Payment::STATUS_COMPLETED, 'paid_at' => '2026-08-24 13:00:00']);
    }

    private function quotation(Branch $branch, string $status, string $validUntil): void
    {
        Quotation::create(['quotation_number' => 'Q-'.$branch->code.'-'.uniqid(), 'branch_id' => $branch->id, 'status' => $status, 'valid_until' => $validUntil, 'subtotal' => 100000, 'discount_total' => 0, 'total' => 100000]);
    }
}
