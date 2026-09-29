<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OperationalAuthorizationTest extends TestCase
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

    public function test_admin_can_list_orders(): void
    {
        $this->actingAsRole('admin');
        $this->getJson('/api/admin/orders')->assertOk();
    }

    public function test_sales_collaborator_can_list_view_create_pay_and_complete_orders(): void
    {
        $collaborator = $this->actingAsRole('admin');
        $product = $this->product();

        $this->getJson('/api/admin/orders')->assertOk();
        $orderId = $this->postJson('/api/admin/orders', [
            'branch_id' => $this->branch()->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated()->json('data.id');

        $this->getJson("/api/admin/orders/{$orderId}")->assertOk();
        $this->postJson("/api/admin/orders/{$orderId}/payments", [
            'amount' => 100000,
            'method' => 'cash',
        ])->assertCreated()->assertJsonPath('data.created_by', $collaborator->id);
        $this->postJson("/api/admin/orders/{$orderId}/status", ['status' => 'completed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
    }

    public function test_user_cannot_confirm_or_cancel_pending_order(): void
    {
        $product = $this->product();
        $pending = $this->pendingOrder($product);
        $this->actingAsRole('user');

        $this->postJson("/api/admin/orders/{$pending->id}/status", ['status' => 'confirmed'])
            ->assertForbidden();
        $this->postJson("/api/admin/orders/{$pending->id}/status", ['status' => 'cancelled'])
            ->assertForbidden();
        $this->assertSame('pending', $pending->fresh()->status);
    }

    public function test_admin_can_cancel_confirmed_order(): void
    {
        $product = $this->product();
        $pending = $this->pendingOrder($product);
        $this->actingAsRole('admin');
        $this->postJson("/api/admin/orders/{$pending->id}/status", [
            'status' => 'confirmed',
            'branch_id' => $this->branch()->id,
        ])->assertOk();
        $this->postJson("/api/admin/orders/{$pending->id}/status", ['status' => 'cancelled'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_user_without_permissions_receives_forbidden(): void
    {
        $this->actingAsRole('viewer');
        $this->getJson('/api/admin/orders')->assertForbidden();
        $this->getJson('/api/admin/inventory')->assertForbidden();
        $this->getJson('/api/admin/products')->assertForbidden();
    }

    public function test_guest_receives_unauthorized_on_admin_endpoints(): void
    {
        $this->getJson('/api/admin/orders')->assertUnauthorized();
        $this->postJson('/api/admin/orders', [])->assertUnauthorized();
        $this->getJson('/api/admin/inventory')->assertUnauthorized();
    }

    public function test_sales_collaborator_can_read_inventory_movements_products_and_variants(): void
    {
        $product = $this->product();
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Variante operativa',
            'normalized_name' => 'variante operativa',
            'sku' => 'VAR-OPERATIVA',
            'price' => 120000,
            'is_active' => true,
            'is_visible' => true,
        ]);
        $this->actingAsRole('user');

        $this->getJson('/api/admin/inventory')
            ->assertForbidden();
        $this->getJson('/api/admin/inventory/movements')->assertForbidden();
        $this->getJson('/api/admin/products')
            ->assertForbidden();
    }

    public function test_sales_collaborator_cannot_adjust_stock_or_modify_catalog(): void
    {
        $product = $this->product();
        $this->actingAsRole('user');

        $this->postJson('/api/admin/inventory/movements', [
            'product_id' => $product->id,
            'type' => 'adjustment',
            'new_stock' => 99,
        ])->assertForbidden();
        $this->postJson('/api/admin/products', [])->assertForbidden();
        $this->postJson("/api/admin/products/{$product->id}", [])->assertForbidden();
        $this->deleteJson("/api/admin/products/{$product->id}")->assertForbidden();
        $this->assertSame(5, $this->stock($product)->fresh()->quantity);
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function product(): Product
    {
        $product = Product::create([
            'name' => 'Producto operativo',
            'slug' => 'producto-operativo-'.uniqid(),
            'sku' => 'PROD-'.uniqid(),
            'price' => 100000,
            'cost_price' => 40000,
            'is_active' => true,
            'is_visible' => true,
        ]);

        $item = InventoryItem::firstOrCreate([
            'product_id' => $product->id,
            'product_variant_id' => null,
        ]);
        InventoryStock::create([
            'branch_id' => $this->branch()->id,
            'inventory_item_id' => $item->id,
            'quantity' => 5,
            'minimum_quantity' => 0,
        ]);

        return $product;
    }

    private function branch(): Branch
    {
        return Branch::firstOrCreate(
            ['code' => 'OPER'],
            ['slug' => 'operaciones', 'name' => 'Sede operaciones', 'city' => 'Barranquilla', 'is_active' => true],
        );
    }

    private function stock(Product $product): InventoryStock
    {
        return InventoryStock::query()
            ->where('branch_id', $this->branch()->id)
            ->whereHas('inventoryItem', fn ($query) => $query->where('product_id', $product->id))
            ->firstOrFail();
    }

    private function pendingOrder(Product $product): Order
    {
        $response = $this->withHeader('Idempotency-Key', __METHOD__.'-'.$product->id)->postJson('/api/orders', [
            'customer_name' => 'Cliente',
            'customer_email' => 'cliente@example.com',
            'customer_phone' => '3000000000',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated();

        return Order::findOrFail($response->json('data.id'));
    }
}
