<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardPhaseOneTest extends TestCase
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
            $app['config']->set("database.connections.{$connection}.database", getenv('TEST_DB_DATABASE') ?: 'upgrade_dashboard_test');
        }

        return $app;
    }

    public function test_dashboard_authorization_and_financial_visibility(): void
    {
        $this->getJson('/api/admin/dashboard')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create(['role' => 'viewer', 'is_active' => true]));
        $this->getJson('/api/admin/dashboard')->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => 'user', 'is_active' => true]));
        $this->getJson('/api/admin/dashboard')->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));
        $this->getJson('/api/admin/dashboard')->assertOk()
            ->assertJsonPath('data.period.timezone', 'America/Bogota')
            ->assertJsonPath('data.period.week_starts_on', 'monday')
            ->assertJsonStructure(['data' => ['financials']]);
    }

    public function test_sales_use_confirmed_at_colombia_periods_and_return_complete_series(): void
    {
        $this->actingAsAdmin();
        $this->order(Order::STATUS_CONFIRMED, '2026-08-13 05:30:00', 100000, Order::ORIGIN_CRM, '2025-01-01 00:00:00');
        $this->order(Order::STATUS_COMPLETED, '2026-08-13 04:30:00', 200000, Order::ORIGIN_ECOMMERCE);
        $this->order(Order::STATUS_CONFIRMED, '2026-08-10 13:00:00', 300000, Order::ORIGIN_ECOMMERCE);
        $this->order(Order::STATUS_PENDING, '2026-08-13 13:00:00', 900000);
        $this->order(Order::STATUS_CANCELLED, '2026-08-13 13:00:00', 900000);

        $response = $this->getJson('/api/admin/dashboard')->assertOk();
        $response->assertJsonPath('data.sales.today.total', 100000)
            ->assertJsonPath('data.sales.today.orders_count', 1)
            ->assertJsonPath('data.sales.week.total', 600000)
            ->assertJsonPath('data.sales.month.total', 600000)
            ->assertJsonPath('data.sales.by_origin_month.crm', 100000)
            ->assertJsonPath('data.sales.by_origin_month.ecommerce', 500000);
        $this->assertCount(30, $response->json('data.sales.last_30_days'));
        $this->assertSame('2026-07-15', $response->json('data.sales.last_30_days.0.date'));
        $this->assertSame('2026-08-13', $response->json('data.sales.last_30_days.29.date'));
        $this->assertCount(3, $response->json('data.recent_orders'));
    }

    public function test_payments_and_outstanding_use_only_completed_payments_on_valid_orders(): void
    {
        $this->actingAsAdmin();
        $partial = $this->order(Order::STATUS_CONFIRMED, '2026-08-13 12:00:00', 100000);
        $paid = $this->order(Order::STATUS_COMPLETED, '2026-08-12 12:00:00', 50000);
        $pendingOrder = $this->order(Order::STATUS_PENDING, '2026-08-13 12:00:00', 70000);
        $cancelled = $this->order(Order::STATUS_CANCELLED, '2026-08-13 12:00:00', 80000);
        $this->payment($partial, Payment::STATUS_COMPLETED, 40000, '2026-08-13 05:15:00');
        $this->payment($partial, Payment::STATUS_PENDING, 50000, '2026-08-13 06:00:00');
        $this->payment($partial, Payment::STATUS_FAILED, 50000, '2026-08-13 06:00:00');
        $this->payment($paid, Payment::STATUS_COMPLETED, 50000, '2026-08-01 10:00:00');
        $this->payment($pendingOrder, Payment::STATUS_COMPLETED, 70000, '2026-08-13 07:00:00');
        $this->payment($cancelled, Payment::STATUS_COMPLETED, 80000, '2026-08-13 08:00:00');

        $this->getJson('/api/admin/dashboard')->assertOk()
            ->assertJsonPath('data.payments.received_today', 190000)
            ->assertJsonPath('data.payments.received_month', 240000)
            ->assertJsonPath('data.payments.outstanding', 60000)
            ->assertJsonPath('data.payments.orders_with_balance', 1);
    }

    public function test_quotation_customer_inventory_and_top_product_metrics(): void
    {
        $this->actingAsAdmin();
        $customer = Customer::create(['name' => 'Cliente', 'is_active' => true]);
        $inactive = Customer::create(['name' => 'Inactivo', 'is_active' => false]);
        $customer->forceFill(['created_at' => '2026-08-02 10:00:00', 'updated_at' => '2026-08-02 10:00:00'])->save();
        $inactive->forceFill(['created_at' => '2026-07-02 10:00:00', 'updated_at' => '2026-07-02 10:00:00'])->save();
        $order = $this->order(Order::STATUS_CONFIRMED, '2026-08-12 12:00:00', 180000, customer: $customer);
        $this->order(Order::STATUS_COMPLETED, '2026-08-11 12:00:00', 90000, customer: $customer);
        $product = $this->product('Simple bajo');
        $parent = $this->product('Con variantes');
        $this->variant($parent, 'Alta', true);
        $critical = $this->variant($parent, 'Agotada', true);
        $this->variant($parent, 'Inactiva', false);
        $branch = Branch::create(['code' => 'QA', 'slug' => 'qa', 'name' => 'QA', 'city' => 'QA', 'is_active' => true]);
        $this->inventoryStock($branch, $product, null, 2, 3);
        $this->inventoryStock($branch, $parent, $critical, 0, 3);
        $this->item($order, $product, 2, 90000, 60000);

        $this->quotation(Quotation::STATUS_DRAFT, '2026-08-13');
        $this->quotation(Quotation::STATUS_SENT, '2026-08-16');
        $this->quotation(Quotation::STATUS_SENT, '2026-08-17');
        $this->quotation(Quotation::STATUS_DRAFT, '2026-08-12');
        $this->quotation(Quotation::STATUS_REJECTED, '2026-08-20');
        $converted = $this->quotation(Quotation::STATUS_CONVERTED, '2026-08-20');
        $converted->forceFill(['order_id' => $order->id, 'converted_at' => '2026-08-05 12:00:00'])->save();

        $response = $this->getJson('/api/admin/dashboard')->assertOk();
        $response->assertJsonPath('data.quotations.open', 3)
            ->assertJsonPath('data.quotations.expiring_soon', 2)
            ->assertJsonPath('data.quotations.converted_month', 1)
            ->assertJsonPath('data.quotations.conversion_rate', 33.33)
            ->assertJsonPath('data.customers.total', 2)
            ->assertJsonPath('data.customers.active', 1)
            ->assertJsonPath('data.customers.new_month', 1)
            ->assertJsonPath('data.customers.buyers_month', 1)
            ->assertJsonPath('data.inventory.low_stock_count', 1)
            ->assertJsonPath('data.inventory.out_of_stock_count', 1)
            ->assertJsonPath('data.low_stock.0.product_variant_id', $critical->id)
            ->assertJsonPath('data.top_products.0.quantity', 2);
    }

    public function test_financials_use_known_cost_coverage_without_assuming_unknown_cost_is_zero(): void
    {
        $this->actingAsAdmin();
        $order = $this->order(Order::STATUS_CONFIRMED, '2026-08-13 12:00:00', 180000);
        $known = $this->product('Con costo');
        $unknown = $this->product('Sin costo');
        $this->item($order, $known, 2, 50000, 30000);
        $this->item($order, $unknown, 1, 80000, null);

        $this->getJson('/api/admin/dashboard')->assertOk()
            ->assertJsonPath('data.financials.revenue_month', 180000)
            ->assertJsonPath('data.financials.known_cost_revenue_month', 100000)
            ->assertJsonPath('data.financials.cogs_month', 60000)
            ->assertJsonPath('data.financials.gross_profit_on_known_cost_month', 40000)
            ->assertJsonPath('data.financials.gross_margin_percent', 40)
            ->assertJsonPath('data.financials.cost_coverage_percent', 55.56);
    }

    private function actingAsAdmin(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));
    }

    private function order(string $status, string $confirmedAt, int $total, string $origin = Order::ORIGIN_CRM, ?string $createdAt = null, ?Customer $customer = null): Order
    {
        $order = Order::create([
            'order_number' => 'ORD-'.uniqid(), 'origin' => $origin, 'customer_id' => $customer?->id,
            'customer_name' => $customer?->name, 'subtotal' => $total, 'discount_total' => 0,
            'total' => $total, 'status' => $status, 'payment_status' => Order::PAYMENT_UNPAID,
            'confirmed_at' => $confirmedAt,
        ]);
        if ($createdAt) {
            $order->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();
        }

        return $order;
    }

    private function payment(Order $order, string $status, int $amount, string $paidAt): Payment
    {
        return Payment::create([
            'order_id' => $order->id, 'amount' => $amount, 'method' => Payment::METHOD_CASH,
            'status' => $status, 'paid_at' => $paidAt,
        ]);
    }

    private function quotation(string $status, string $validUntil): Quotation
    {
        return Quotation::create([
            'quotation_number' => 'COT-'.uniqid(), 'status' => $status, 'valid_until' => $validUntil,
            'subtotal' => 100000, 'discount_total' => 0, 'total' => 100000,
        ]);
    }

    private function product(string $name): Product
    {
        return Product::create([
            'name' => $name, 'slug' => 'product-'.uniqid(), 'sku' => 'SKU-'.uniqid(),
            'price' => 90000, 'cost_price' => 30000, 'is_active' => true,
        ]);
    }

    private function variant(Product $product, string $name, bool $active): ProductVariant
    {
        return ProductVariant::create([
            'product_id' => $product->id, 'name' => $name, 'normalized_name' => strtolower($name),
            'sku' => 'VAR-'.uniqid(), 'price' => 90000, 'is_active' => $active,
        ]);
    }

    private function item(Order $order, Product $product, int $quantity, int $unitPrice, ?int $unitCost): OrderItem
    {
        return OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id, 'product_name' => $product->name,
            'product_slug' => $product->slug, 'product_sku' => $product->sku, 'unit_price' => $unitPrice,
            'unit_cost' => $unitCost, 'quantity' => $quantity, 'subtotal' => $unitPrice * $quantity,
            'discount_amount' => 0, 'total' => $unitPrice * $quantity,
        ]);
    }

    private function inventoryStock(Branch $branch, Product $product, ?ProductVariant $variant, int $quantity, int $minimum): void
    {
        $item = InventoryItem::create($variant
            ? ['product_variant_id' => $variant->id]
            : ['product_id' => $product->id]);
        InventoryStock::create(['branch_id' => $branch->id, 'inventory_item_id' => $item->id, 'quantity' => $quantity, 'minimum_quantity' => $minimum]);
    }
}
