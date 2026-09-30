<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderSalesPhaseOneTest extends TestCase
{
    use RefreshDatabase;

    public function createApplication()
    {
        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        if ($connection = getenv('TEST_DB_CONNECTION')) {
            $app['config']->set('database.default', $connection);
            $app['config']->set("database.connections.{$connection}.database", getenv('TEST_DB_DATABASE') ?: 'upgrade_orders_test');
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_public_simple_order_is_pending_uses_backend_price_and_preserves_stock_and_snapshots(): void
    {
        $product = $this->product(price: 250000, cost: 100000);
        $response = $this->withHeader('Idempotency-Key', __METHOD__.'-invalid')->postJson('/api/orders', $this->publicPayload($product, ['unit_price' => 1]));
        $response->assertUnprocessable()->assertJsonValidationErrors('items.0.unit_price');

        $this->withHeader('Idempotency-Key', __METHOD__.'-valid')->postJson('/api/orders', $this->publicPayload($product))->assertCreated()->assertJsonPath('data.status', 'pending');
        $this->assertDatabaseHas('order_items', ['product_id' => $product->id, 'product_name' => $product->name, 'product_sku' => $product->sku, 'unit_price' => 250000, 'unit_cost' => 100000, 'quantity' => 2, 'subtotal' => 500000, 'total' => 500000]);
    }

    public function test_public_rejects_inactive_product_but_defers_stock_validation_until_confirmation(): void
    {
        $inactive = $this->product(active: false);
        $this->withHeader('Idempotency-Key', __METHOD__.'-inactive')->postJson('/api/orders', $this->publicPayload($inactive))->assertUnprocessable();
        $low = $this->product();
        $this->withHeader('Idempotency-Key', __METHOD__.'-low-stock')->postJson('/api/orders', $this->publicPayload($low))
            ->assertCreated()
            ->assertJsonPath('data.status', Order::STATUS_PENDING)
            ->assertJsonMissingPath('data.branch_id');
    }

    public function test_variants_are_validated_priced_stocked_and_kept_as_separate_lines(): void
    {
        $product = $this->product(price: 1);
        $android = $this->variant($product, 'Android', 700000, true);
        $linux = $this->variant($product, 'Linux', 600000);
        $payload = $this->publicPayload($product, ['product_variant_id' => $android->id, 'quantity' => 1]);
        $payload['items'][] = ['product_id' => $product->id, 'product_variant_id' => $linux->id, 'quantity' => 1];
        $this->withHeader('Idempotency-Key', __METHOD__.'-variants')->postJson('/api/orders', $payload)->assertCreated();
        $this->assertSame(2, Order::latest()->first()->items()->count());
        $this->assertDatabaseHas('order_items', ['product_variant_id' => $android->id, 'variant_sku' => $android->sku, 'unit_price' => 700000]);

        $other = $this->product();
        $this->withHeader('Idempotency-Key', __METHOD__.'-wrong-product')->postJson('/api/orders', $this->publicPayload($other, ['product_variant_id' => $android->id]))->assertUnprocessable();
        $this->withHeader('Idempotency-Key', __METHOD__.'-default')->postJson('/api/orders', $this->publicPayload($product))->assertCreated()->assertJsonPath('data.items.0.product_variant_id', $android->id);
    }

    public function test_public_order_response_contains_only_public_order_and_item_snapshots(): void
    {
        $product = $this->product(price: 700000, cost: 320000);
        $variant = $this->variant($product, '8/128', 750000, true);
        $response = $this->withHeader('Idempotency-Key', __METHOD__)->postJson('/api/orders', $this->publicPayload($product, [
            'product_variant_id' => $variant->id,
            'quantity' => 1,
        ]))->assertCreated();

        $data = $response->json('data');
        $item = $data['items'][0];

        $this->assertSame(750000, $data['total']);
        $this->assertSame($product->id, $item['product_id']);
        $this->assertSame($variant->id, $item['product_variant_id']);
        $this->assertSame($product->name, $item['product_name']);
        $this->assertSame($variant->sku, $item['variant_sku']);
        $this->assertSame([
            'id', 'order_number', 'status', 'payment_status', 'customer_name', 'customer_email',
            'customer_phone', 'customer_city', 'customer_address', 'subtotal', 'discount_total',
            'charges_total', 'currency', 'total', 'items', 'created_at',
        ], array_keys($data));
        $this->assertSame([
            'id', 'item_type', 'product_id', 'product_variant_id', 'product_name', 'product_slug',
            'product_sku', 'variant_name', 'variant_sku', 'variant_specs', 'quantity', 'unit_price',
            'subtotal', 'discount_amount', 'total',
        ], array_keys($item));

        foreach (['branch_id', 'created_by', 'customer_id', 'customer_notes', 'payment_provider', 'payment_reference', 'payments', 'product', 'product_variant'] as $field) {
            $this->assertArrayNotHasKey($field, $data);
        }
        foreach (['unit_cost', 'cost_price', 'tax_amount', 'extra_charges', 'total_cost', 'margin', 'profit', 'commission', 'inventory', 'branch_stock', 'product', 'product_variant'] as $field) {
            $this->assertArrayNotHasKey($field, $item);
        }
    }

    public function test_product_with_variants_without_default_requires_selection(): void
    {
        $product = $this->product();
        $this->variant($product, 'A', 1000);
        $this->withHeader('Idempotency-Key', __METHOD__)->postJson('/api/orders', $this->publicPayload($product))->assertUnprocessable();
    }

    public function test_admin_sale_can_have_no_customer_and_confirms_product_stock_once(): void
    {
        $admin = $this->admin();
        $branch = $this->branch();
        $product = $this->product(price: 100000);
        $stock = $this->seedStock($branch, $product, 5);
        $response = $this->postJson('/api/admin/orders', ['branch_id' => $branch->id, 'items' => [['product_id' => $product->id, 'quantity' => 2, 'discount_amount' => 10000]]]);
        $response->assertCreated()->assertJsonPath('data.origin', 'crm')->assertJsonPath('data.status', 'confirmed');
        $order = Order::latest()->first();
        $this->assertSame(3, $stock->fresh()->quantity);
        $this->assertDatabaseHas('inventory_movements', ['type' => 'sale', 'reference_type' => Order::class, 'reference_id' => $order->id, 'quantity_delta' => -2]);
        $this->postJson("/api/admin/orders/{$order->id}/status", ['status' => 'confirmed'])->assertOk();
        $this->assertSame(3, $stock->fresh()->quantity);
        $this->assertSame($admin->id, $order->fresh()->created_by);
    }

    public function test_admin_variant_sale_and_cancel_moves_only_variant_stock_once(): void
    {
        $this->admin();
        $branch = $this->branch();
        $product = $this->product();
        $variant = $this->variant($product, '8/128', 800000, true);
        $stock = $this->seedStock($branch, $product, 4, $variant);
        $orderId = $this->postJson('/api/admin/orders', ['branch_id' => $branch->id, 'items' => [['product_id' => $product->id, 'product_variant_id' => $variant->id, 'quantity' => 1]]])->assertCreated()->json('data.id');
        $this->assertSame(3, $stock->fresh()->quantity);
        $this->postJson("/api/admin/orders/{$orderId}/status", ['status' => 'cancelled', 'reason' => 'Cliente desistió'])->assertOk();
        $this->postJson("/api/admin/orders/{$orderId}/status", ['status' => 'cancelled'])->assertOk();
        $this->assertSame(4, $stock->fresh()->quantity);
        $this->assertDatabaseCount('inventory_movements', 2);
    }

    public function test_state_machine_history_timestamps_and_stock_failure_are_consistent(): void
    {
        $this->admin();
        $branch = $this->branch();
        $product = $this->product();
        $stock = $this->seedStock($branch, $product, 1);
        $orderId = $this->withHeader('Idempotency-Key', __METHOD__)->postJson('/api/orders', $this->publicPayload($product, ['quantity' => 1]))->assertCreated()->json('data.id');
        $stock->update(['quantity' => 0]);
        $this->postJson("/api/admin/orders/{$orderId}/status", ['status' => 'confirmed', 'branch_id' => $branch->id])->assertUnprocessable();
        $this->assertSame('pending', Order::find($orderId)->status);
        $stock->update(['quantity' => 1]);
        $this->postJson("/api/admin/orders/{$orderId}/status", ['status' => 'confirmed', 'branch_id' => $branch->id])->assertOk();
        $this->postJson("/api/admin/orders/{$orderId}/status", ['status' => 'completed'])->assertOk();
        $this->postJson("/api/admin/orders/{$orderId}/status", ['status' => 'cancelled'])->assertUnprocessable();
        $order = Order::find($orderId);
        $this->assertNotNull($order->confirmed_at);
        $this->assertNotNull($order->completed_at);
        $this->assertSame(3, $order->statusHistory()->count());
    }

    public function test_pending_cancel_does_not_move_stock_and_terminal_cannot_confirm(): void
    {
        $this->admin();
        $product = $this->product();
        $id = $this->withHeader('Idempotency-Key', __METHOD__)->postJson('/api/orders', $this->publicPayload($product))->json('data.id');
        $this->postJson("/api/admin/orders/{$id}/status", ['status' => 'cancelled'])->assertOk();
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->postJson("/api/admin/orders/{$id}/status", ['status' => 'confirmed'])->assertUnprocessable();
    }

    public function test_payments_support_partial_total_second_payment_and_reject_overpayment(): void
    {
        $admin = $this->admin();
        $branch = $this->branch();
        $product = $this->product(price: 100000);
        $stock = $this->seedStock($branch, $product, 4);
        $id = $this->postJson('/api/admin/orders', ['branch_id' => $branch->id, 'items' => [['product_id' => $product->id, 'quantity' => 2]], 'payment' => ['amount' => 50000, 'method' => 'cash']])->assertCreated()->json('data.id');
        $this->assertSame('partial', Order::find($id)->payment_status);
        $this->postJson("/api/admin/orders/{$id}/payments", ['amount' => 150000, 'method' => 'transfer'])->assertCreated();
        $order = Order::findOrFail($id);
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame(Order::STATUS_CONFIRMED, $order->status);
        $this->assertSame(2, $stock->fresh()->quantity);
        $this->assertDatabaseCount('employee_commissions', 0);
        $this->assertDatabaseHas('payments', ['order_id' => $id, 'created_by' => $admin->id]);
        $this->postJson("/api/admin/orders/{$id}/payments", ['amount' => 1, 'method' => 'cash'])->assertUnprocessable();
    }

    public function test_manual_payment_state_matrix_allows_open_and_completed_sales_but_rejects_terminal_financial_states(): void
    {
        $this->admin();
        $pending = $this->order(Order::STATUS_PENDING, Order::PAYMENT_UNPAID, 100000);

        $this->postJson("/api/admin/orders/{$pending->id}/payments", [
            'amount' => 40000,
            'method' => 'cash',
        ])->assertCreated()->assertJsonPath('order.status', Order::STATUS_PENDING)
            ->assertJsonPath('order.payment_status', Order::PAYMENT_PARTIAL);
        $this->postJson("/api/admin/orders/{$pending->id}/payments", [
            'amount' => 60000,
            'method' => 'transfer',
        ])->assertCreated()->assertJsonPath('order.status', Order::STATUS_PENDING)
            ->assertJsonPath('order.payment_status', Order::PAYMENT_PAID);
        $this->postJson("/api/admin/orders/{$pending->id}/payments", [
            'amount' => 1,
            'method' => 'cash',
        ])->assertUnprocessable()->assertJsonValidationErrors('amount');

        $completed = $this->order(Order::STATUS_COMPLETED, Order::PAYMENT_UNPAID, 80000);
        $this->postJson("/api/admin/orders/{$completed->id}/payments", [
            'amount' => 80000,
            'method' => 'card_terminal',
        ])->assertCreated()->assertJsonPath('order.status', Order::STATUS_COMPLETED)
            ->assertJsonPath('order.payment_status', Order::PAYMENT_PAID);

        $cancelled = $this->order(Order::STATUS_CANCELLED, Order::PAYMENT_UNPAID, 50000);
        $this->postJson("/api/admin/orders/{$cancelled->id}/payments", [
            'amount' => 50000,
            'method' => 'cash',
        ])->assertUnprocessable()->assertJsonValidationErrors('order');

        $refunded = $this->order(Order::STATUS_CONFIRMED, Order::PAYMENT_REFUNDED, 50000);
        $this->postJson("/api/admin/orders/{$refunded->id}/payments", [
            'amount' => 50000,
            'method' => 'cash',
        ])->assertUnprocessable()->assertJsonValidationErrors('order');

        $this->assertSame(2, $pending->payments()->count());
        $this->assertSame(1, $completed->payments()->count());
        $this->assertSame(0, $cancelled->payments()->count());
        $this->assertSame(0, $refunded->payments()->count());
    }

    public function test_manual_payment_rejects_invalid_amounts_and_revalidates_stale_repeated_requests(): void
    {
        $this->admin();
        $order = $this->order(Order::STATUS_CONFIRMED, Order::PAYMENT_UNPAID, 100000);

        foreach ([0, -1, 100001] as $amount) {
            $this->postJson("/api/admin/orders/{$order->id}/payments", [
                'amount' => $amount,
                'method' => 'cash',
            ])->assertUnprocessable()->assertJsonValidationErrors('amount');
        }
        $this->assertSame(0, $order->payments()->count());

        $payload = ['amount' => 100000, 'method' => 'transfer', 'reference' => 'same-stale-submit'];
        $this->postJson("/api/admin/orders/{$order->id}/payments", $payload)->assertCreated();
        $this->postJson("/api/admin/orders/{$order->id}/payments", $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('amount');

        $this->assertSame(1, $order->payments()->count());
        $this->assertSame(Order::PAYMENT_PAID, $order->fresh()->payment_status);
    }

    public function test_authenticated_user_without_permissions_cannot_access_orders(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'viewer']));
        $product = $this->product();
        $order = Order::create(['order_number' => 'TEST-1', 'origin' => 'ecommerce', 'subtotal' => 1, 'discount_total' => 0, 'total' => 1, 'status' => 'pending', 'payment_status' => 'unpaid']);
        $this->getJson('/api/admin/orders')->assertForbidden();
        $this->getJson("/api/admin/orders/{$order->id}")->assertForbidden();
        $this->postJson('/api/admin/orders', ['items' => [['product_id' => $product->id, 'quantity' => 1]]])->assertForbidden();
        $this->postJson("/api/admin/orders/{$order->id}/status", ['status' => 'cancelled'])->assertForbidden();
        $this->postJson("/api/admin/orders/{$order->id}/payments", ['amount' => 1, 'method' => 'cash'])->assertForbidden();
    }

    public function test_legacy_item_without_variant_and_legacy_payment_columns_serialize(): void
    {
        $order = Order::create(['order_number' => 'LEGACY-1', 'origin' => 'ecommerce', 'customer_name' => 'Antes', 'subtotal' => 100, 'discount_total' => 0, 'total' => 100, 'status' => 'pending', 'payment_status' => 'unpaid', 'payment_provider' => 'legacy']);
        OrderItem::create(['order_id' => $order->id, 'product_name' => 'Histórico', 'unit_price' => 100, 'quantity' => 1, 'total' => 100]);
        $this->admin();
        $this->getJson("/api/admin/orders/{$order->id}")->assertOk()->assertJsonPath('data.items.0.product_variant_id', null)->assertJsonPath('data.payment_provider', 'legacy');
    }

    private function admin(): User
    {
        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user);

        return $user;
    }

    private function product(int $price = 100000, int $cost = 40000, bool $active = true): Product
    {
        return Product::create(['name' => 'Producto '.uniqid(), 'slug' => 'producto-'.uniqid(), 'sku' => 'P-'.uniqid(), 'price' => $price, 'cost_price' => $cost, 'is_active' => $active, 'is_visible' => true]);
    }

    private function order(string $status, string $paymentStatus, int $total): Order
    {
        return Order::create([
            'order_number' => 'PAY-'.uniqid(),
            'origin' => Order::ORIGIN_CRM,
            'subtotal' => $total,
            'discount_total' => 0,
            'total' => $total,
            'status' => $status,
            'payment_status' => $paymentStatus,
        ]);
    }

    private function variant(Product $product, string $name, int $price, bool $default = false): ProductVariant
    {
        return ProductVariant::create(['product_id' => $product->id, 'name' => $name, 'normalized_name' => strtolower($name), 'sku' => 'V-'.uniqid(), 'price' => $price, 'cost_price' => 200000, 'is_default' => $default, 'is_active' => true, 'is_visible' => true]);
    }

    private function publicPayload(Product $product, array $item = []): array
    {
        return ['customer_name' => 'Cliente Prueba', 'customer_email' => 'cliente@example.com', 'customer_phone' => '+57 3000000000', 'items' => [[...['product_id' => $product->id, 'quantity' => 2], ...$item]]];
    }

    private function branch(): Branch
    {
        return Branch::firstOrCreate(
            ['code' => 'ORD'],
            ['slug' => 'ordenes', 'name' => 'Sede órdenes', 'city' => 'Barranquilla', 'is_active' => true],
        );
    }

    private function seedStock(Branch $branch, Product $product, int $quantity, ?ProductVariant $variant = null): InventoryStock
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
}
