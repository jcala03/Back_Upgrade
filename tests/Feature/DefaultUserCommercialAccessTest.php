<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\InventoryItem;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DefaultUserCommercialAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_user_operates_own_branch_without_administrative_access(): void
    {
        $baq = Branch::create(['code' => 'BAQ', 'slug' => 'baq', 'name' => 'Barranquilla', 'city' => 'Barranquilla', 'is_active' => true]);
        $bog = Branch::create(['code' => 'BOG', 'slug' => 'bog', 'name' => 'Bogotá', 'city' => 'Bogotá', 'is_active' => true]);
        $user = User::factory()->create(['role' => 'user', 'is_active' => true]);
        $employee = Employee::create(['user_id' => $user->id, 'branch_id' => $baq->id, 'name' => 'Comercial', 'job_title' => 'Asesor', 'is_active' => true]);
        $product = Product::create(['name' => 'Producto', 'slug' => 'producto', 'sku' => 'SKU-QA', 'price' => 100000, 'is_active' => true, 'is_visible' => true]);
        $item = InventoryItem::firstOrCreate(['product_id' => $product->id, 'product_variant_id' => null]);
        $stock = InventoryStock::create(['branch_id' => $baq->id, 'inventory_item_id' => $item->id, 'quantity' => 5, 'minimum_quantity' => 0]);
        InventoryStock::create(['branch_id' => $bog->id, 'inventory_item_id' => $item->id, 'quantity' => 99, 'minimum_quantity' => 0]);
        Sanctum::actingAs($user);
        $this->getJson('/api/auth/me')->assertOk()->assertJsonFragment(['inventory.view_own']);
        $this->getJson('/api/my/commercial-products?search=SKU-QA')->assertOk()
            ->assertJsonPath('data.branch.id', $baq->id)->assertJsonPath('data.products.0.branch_stock', 5);
        $this->getJson('/api/my/commercial-products?branch_id='.$bog->id)->assertUnprocessable();
        $payload = ['customer_name' => 'Cliente QA', 'items' => [['product_id' => $product->id, 'quantity' => 1]]];
        $quote = $this->postJson('/api/my/quotations', $payload)->assertCreated()->json('data.id');
        $this->getJson('/api/my/quotations')->assertOk();
        $this->getJson('/api/my/quotations/'.$quote)->assertOk();
        $this->patchJson('/api/my/quotations/'.$quote, [...$payload, 'notes' => 'Actualizada'])->assertOk();
        $this->postJson('/api/my/quotations/'.$quote.'/send')->assertOk();
        $this->postJson('/api/my/quotations/'.$quote.'/convert')->assertCreated();
        $sale = $this->postJson('/api/my/sales', $payload)->assertCreated()->json('data.id');
        $this->getJson('/api/my/sales')->assertOk();
        $this->getJson('/api/my/sales/'.$sale)->assertOk();
        $this->postJson('/api/my/sales/'.$sale.'/confirm')->assertOk();
        $this->assertLessThan(5, $stock->fresh()->quantity);
        $this->postJson('/api/my/sales/'.$sale.'/payments', ['amount' => 100000, 'method' => 'cash'])->assertCreated();
        $this->postJson('/api/my/sales/'.$sale.'/complete')->assertOk();
        $this->postJson('/api/my/sales', [...$payload, 'branch_id' => $bog->id])->assertUnprocessable();
        foreach (['/api/admin/users', '/api/admin/settings', '/api/admin/inventory/stocks', '/api/admin/orders', '/api/admin/quotations'] as $url) {
            $this->getJson($url)->assertForbidden();
        }
        $this->postJson('/api/admin/orders', $payload)->assertForbidden();
        $quantity = $stock->fresh()->quantity;
        $this->postJson('/api/admin/inventory/movements', [
            'branch_id' => $baq->id, 'product_id' => $product->id, 'type' => 'entry', 'quantity' => 100,
        ])->assertForbidden();
        $this->postJson('/api/admin/inventory/transfers', [])->assertForbidden();
        $this->putJson('/api/admin/users/'.$user->id.'/capabilities', ['capabilities' => []])->assertForbidden();
        $this->postJson('/api/admin/orders/'.$sale.'/status', ['status' => 'cancelled'])->assertForbidden();
        $this->assertSame($quantity, $stock->fresh()->quantity);
        $other = User::factory()->create(['role' => 'user', 'is_active' => true]);
        Employee::create(['user_id' => $other->id, 'branch_id' => $bog->id, 'name' => 'Otro comercial', 'job_title' => 'Asesor', 'is_active' => true]);
        Sanctum::actingAs($other);
        $this->getJson('/api/my/commercial-products')->assertOk()->assertJsonPath('data.branch.id', $bog->id)->assertJsonPath('data.products.0.branch_stock', 99);
        $this->getJson('/api/my/sales/'.$sale)->assertNotFound();
        $this->getJson('/api/my/quotations/'.$quote)->assertNotFound();
        $this->postJson('/api/my/sales/'.$sale.'/payments', ['amount' => 1, 'method' => 'cash'])->assertNotFound();
        $this->patchJson('/api/my/quotations/'.$quote, $payload)->assertNotFound();
        $this->assertSame($baq->id, $employee->fresh()->branch_id);
    }

    public function test_admin_keeps_privileges_and_default_user_has_no_administrative_permissions(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $user = User::factory()->create(['role' => 'user', 'is_active' => true]);
        foreach (['inventory.update', 'inventory_transfers.create', 'users.create', 'roles.manage', 'settings.update', 'orders.cancel', 'products.delete'] as $permission) {
            $this->assertTrue($admin->hasPermission($permission));
            $this->assertFalse($user->hasPermission($permission));
        }
        Sanctum::actingAs($admin);
        $this->getJson('/api/admin/users')->assertOk();
        $this->getJson('/api/admin/orders')->assertOk();
        $this->getJson('/api/admin/quotations')->assertOk();
    }
}
