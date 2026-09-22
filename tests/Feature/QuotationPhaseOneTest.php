<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerVehicle;
use App\Models\InventoryItem;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\User;
use App\Models\VehicleBrand;
use App\Models\VehicleModel;
use App\Models\VehicleVersion;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QuotationPhaseOneTest extends TestCase
{
    use RefreshDatabase;

    private ?Branch $quotationBranch = null;

    public function createApplication()
    {
        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        if ($connection = getenv('TEST_DB_CONNECTION')) {
            $app['config']->set('database.default', $connection);
            $app['config']->set("database.connections.{$connection}.database", getenv('TEST_DB_DATABASE') ?: 'upgrade_quotation_test');
        }

        return $app;
    }

    public function test_admin_and_sales_crud_permissions_and_guest_protection(): void
    {
        $product = $this->product();
        $this->getJson('/api/admin/quotations')->assertUnauthorized();

        $this->actingAsRole('viewer');
        $this->getJson('/api/admin/quotations')->assertForbidden();
        $this->postJson('/api/admin/quotations', $this->payload($product))->assertForbidden();

        foreach (['admin'] as $role) {
            $this->actingAsRole($role);
            $response = $this->postJson('/api/admin/quotations', $this->payload($product))
                ->assertCreated()
                ->assertJsonPath('data.status', 'draft');
            $id = $response->json('data.id');
            $this->getJson('/api/admin/quotations?search='.urlencode($response->json('data.quotation_number')))
                ->assertOk()->assertJsonPath('data.total', 1);
            $this->getJson("/api/admin/quotations/{$id}")->assertOk();
            $this->patchJson("/api/admin/quotations/{$id}", $this->payload($product, ['notes' => 'Actualizada']))
                ->assertOk()->assertJsonPath('data.notes', 'Actualizada');
        }
        $this->actingAsRole('user');
        $this->getJson('/api/admin/quotations')->assertForbidden();
        $this->postJson('/api/admin/quotations', $this->payload($product))->assertForbidden();
    }

    public function test_backend_pricing_variants_discounts_and_prohibited_totals(): void
    {
        $this->actingAsRole('admin');
        $product = $this->product(price: 250000, cost: 90000);
        $variant = $this->variant($product, price: 400000);
        $payload = $this->payload($product, [
            'items' => [[
                'product_id' => $product->id,
                'product_variant_id' => $variant->id,
                'quantity' => 2,
                'discount_amount' => 50000,
            ]],
        ]);
        $response = $this->postJson('/api/admin/quotations', $payload)->assertCreated();
        $response->assertJsonPath('data.subtotal', 800000)
            ->assertJsonPath('data.discount_total', 50000)
            ->assertJsonPath('data.total', 750000)
            ->assertJsonPath('data.items.0.unit_price', 400000)
            ->assertJsonPath('data.items.0.unit_cost', 90000)
            ->assertJsonPath('data.items.0.product_variant_id', $variant->id);
        $quotationId = $response->json('data.id');
        $product->update(['name' => 'Producto cambiado']);
        $variant->update(['name' => 'Variante cambiada', 'sku' => 'SKU-NUEVO', 'price' => 999999]);
        $this->getJson("/api/admin/quotations/{$quotationId}")
            ->assertJsonPath('data.items.0.product_name', $response->json('data.items.0.product_name'))
            ->assertJsonPath('data.items.0.variant_name', 'Variante')
            ->assertJsonPath('data.items.0.variant_sku', $response->json('data.items.0.variant_sku'))
            ->assertJsonPath('data.items.0.unit_price', 400000);

        $payload['items'][0]['unit_price'] = 1;
        $this->postJson('/api/admin/quotations', $payload)->assertUnprocessable()->assertJsonValidationErrors('items.0.unit_price');
        unset($payload['items'][0]['unit_price']);
        $payload['items'][0]['discount_amount'] = 3000000;
        $this->postJson('/api/admin/quotations', $payload)->assertUnprocessable()->assertJsonValidationErrors('items');
    }

    public function test_customer_vehicle_and_ad_hoc_snapshots_are_frozen_and_ownership_is_enforced(): void
    {
        $this->actingAsRole('admin');
        $product = $this->product();
        $customer = Customer::create(['name' => 'Cliente original', 'phone' => '3001234567', 'is_active' => true]);
        $other = Customer::create(['name' => 'Otro', 'is_active' => true]);
        [$brand, $model, $version] = $this->vehicleCatalog();
        $vehicle = CustomerVehicle::create([
            'customer_id' => $customer->id,
            'vehicle_brand_id' => $brand->id,
            'vehicle_model_id' => $model->id,
            'vehicle_version_id' => $version->id,
            'year' => 2018,
            'plate' => 'ABC123',
            'color' => 'Negro',
        ]);

        $id = $this->postJson('/api/admin/quotations', $this->payload($product, [
            'customer_id' => $customer->id,
            'customer_vehicle_id' => $vehicle->id,
            'customer_name' => 'Contradictorio',
            'vehicle_plate' => 'ZZZ999',
        ]))->assertCreated()->assertJsonPath('data.customer_name', 'Cliente original')
            ->assertJsonPath('data.vehicle_plate', 'ABC123')->json('data.id');
        $customer->update(['name' => 'Cliente cambiado']);
        $vehicle->update(['plate' => 'NEW999']);
        $this->getJson("/api/admin/quotations/{$id}")->assertJsonPath('data.customer_name', 'Cliente original')
            ->assertJsonPath('data.vehicle_plate', 'ABC123');

        $this->postJson('/api/admin/quotations', $this->payload($product, [
            'customer_id' => $other->id,
            'customer_vehicle_id' => $vehicle->id,
        ]))->assertUnprocessable()->assertJsonValidationErrors('customer_vehicle_id');

        $this->postJson('/api/admin/quotations', $this->payload($product, [
            'customer_name' => 'Cliente ad hoc',
            'vehicle_brand_id' => $brand->id,
            'vehicle_model_id' => $model->id,
            'vehicle_plate' => 'ADHOC1',
        ]))->assertCreated()->assertJsonPath('data.customer_id', null)
            ->assertJsonPath('data.customer_name', 'Cliente ad hoc')
            ->assertJsonPath('data.customer_vehicle_id', null)
            ->assertJsonPath('data.vehicle_plate', 'ADHOC1');
    }

    public function test_create_update_and_status_changes_never_move_inventory(): void
    {
        $this->actingAsRole('admin');
        $product = $this->product();
        $id = $this->postJson('/api/admin/quotations', $this->payload($product))->assertCreated()->json('data.id');
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->patchJson("/api/admin/quotations/{$id}", $this->payload($product, ['notes' => 'Sin reserva']))->assertOk();
        $this->postJson("/api/admin/quotations/{$id}/status", ['status' => 'sent'])->assertOk();
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_state_machine_expiration_and_terminal_rules(): void
    {
        $this->actingAsRole('admin');
        $product = $this->product();
        $id = $this->postJson('/api/admin/quotations', $this->payload($product))->json('data.id');
        $this->postJson("/api/admin/quotations/{$id}/status", ['status' => 'rejected'])->assertUnprocessable();
        $this->postJson("/api/admin/quotations/{$id}/status", ['status' => 'sent'])->assertOk();
        $this->postJson("/api/admin/quotations/{$id}/status", ['status' => 'rejected', 'reason' => 'No continúa'])->assertOk();
        $this->postJson("/api/admin/quotations/{$id}/convert")->assertUnprocessable();
        $this->patchJson("/api/admin/quotations/{$id}", $this->payload($product))->assertUnprocessable();

        $expired = $this->postJson('/api/admin/quotations', $this->payload($product))->json('data.id');
        Quotation::find($expired)->update(['valid_until' => today()->subDay()]);
        $this->getJson("/api/admin/quotations/{$expired}")->assertOk()->assertJsonPath('data.status', 'expired');
        $this->postJson("/api/admin/quotations/{$expired}/convert")->assertUnprocessable();
        $this->assertDatabaseHas('quotation_status_histories', ['quotation_id' => $expired, 'to_status' => 'expired']);
    }

    public function test_conversion_preserves_quoted_price_revalidates_stock_and_is_single_and_atomic(): void
    {
        $this->actingAsRole('admin');
        $product = $this->product(price: 300000, cost: 120000);
        $inventoryItem = InventoryItem::create(['product_id' => $product->id]);
        $branchStock = InventoryStock::create([
            'branch_id' => $this->branch()->id,
            'inventory_item_id' => $inventoryItem->id,
            'quantity' => 3,
        ]);
        $id = $this->postJson('/api/admin/quotations', $this->payload($product, [
            'items' => [['product_id' => $product->id, 'quantity' => 2, 'discount_amount' => 10000]],
        ]))->assertCreated()->json('data.id');
        $product->update(['price' => 900000]);

        $response = $this->postJson("/api/admin/quotations/{$id}/convert")->assertCreated()
            ->assertJsonPath('data.status', 'converted');
        $orderId = $response->json('data.order_id');
        $order = Order::with('items')->findOrFail($orderId);
        $this->assertSame('confirmed', $order->status);
        $this->assertSame(300000, $order->items->first()->unit_price);
        $this->assertSame(590000, $order->total);
        $this->assertSame(1, $branchStock->fresh()->quantity);
        $this->assertSame($id, $order->quotation_id);
        $this->assertSame($orderId, Quotation::find($id)->order_id);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->postJson("/api/admin/quotations/{$id}/convert")->assertUnprocessable();
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('inventory_movements', 1);

        $second = $this->postJson('/api/admin/quotations', $this->payload($product, [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ]))->assertCreated()->json('data.id');
        $this->postJson("/api/admin/quotations/{$second}/convert")->assertUnprocessable();
        $this->assertSame('draft', Quotation::find($second)->status);
        $this->assertNull(Quotation::find($second)->order_id);
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(1, $branchStock->fresh()->quantity);
    }

    public function test_converted_quotation_cannot_be_edited_and_listing_filters_are_paginated(): void
    {
        $user = $this->actingAsRole('admin');
        $product = $this->product();
        $inventoryItem = InventoryItem::create(['product_id' => $product->id]);
        InventoryStock::create([
            'branch_id' => $this->branch()->id,
            'inventory_item_id' => $inventoryItem->id,
            'quantity' => 1,
        ]);
        $id = $this->postJson('/api/admin/quotations', $this->payload($product, ['customer_name' => 'Buscable']))->json('data.id');
        $this->postJson("/api/admin/quotations/{$id}/convert")->assertCreated();
        $this->patchJson("/api/admin/quotations/{$id}", $this->payload($product))->assertUnprocessable();
        $this->getJson("/api/admin/quotations?status=converted&created_by={$user->id}&search=Buscable")
            ->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.id', $id);
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function product(int $price = 100000, int $cost = 40000): Product
    {
        return Product::create([
            'name' => 'Producto '.uniqid(),
            'slug' => 'producto-'.uniqid(),
            'sku' => 'P-'.uniqid(),
            'price' => $price,
            'cost_price' => $cost,
            'is_active' => true,
            'is_visible' => true,
        ]);
    }

    private function variant(Product $product, int $price): ProductVariant
    {
        return ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Variante',
            'normalized_name' => 'variante',
            'sku' => 'V-'.uniqid(),
            'price' => $price,
            'cost_price' => 90000,
            'is_default' => true,
            'is_active' => true,
            'is_visible' => true,
        ]);
    }

    private function payload(Product $product, array $overrides = []): array
    {
        return [...[
            'branch_id' => $this->branch()->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ], ...$overrides];
    }

    private function branch(): Branch
    {
        return $this->quotationBranch ??= Branch::create([
            'code' => 'BAQ',
            'slug' => 'barranquilla',
            'name' => 'Barranquilla',
            'city' => 'Barranquilla',
            'is_active' => true,
        ]);
    }

    private function vehicleCatalog(): array
    {
        $brand = VehicleBrand::create(['name' => 'Marca '.uniqid(), 'slug' => 'marca-'.uniqid(), 'is_active' => true]);
        $model = VehicleModel::create(['vehicle_brand_id' => $brand->id, 'name' => 'Modelo', 'slug' => 'modelo-'.uniqid(), 'is_active' => true]);
        $version = VehicleVersion::create(['vehicle_model_id' => $model->id, 'name' => 'Versión', 'year_from' => 2015, 'year_to' => 2020, 'is_active' => true]);

        return [$brand, $model, $version];
    }
}
