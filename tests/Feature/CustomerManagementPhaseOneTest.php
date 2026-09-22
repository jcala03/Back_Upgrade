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
use App\Models\User;
use App\Models\VehicleBrand;
use App\Models\VehicleModel;
use App\Models\VehicleVersion;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerManagementPhaseOneTest extends TestCase
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

    public function test_customer_permissions_creation_optional_fields_duplicates_search_update_and_no_delete(): void
    {
        $this->getJson('/api/admin/customers')->assertUnauthorized();
        $this->actingAsRole('viewer');
        $this->getJson('/api/admin/customers')->assertForbidden();

        foreach (['admin'] as $role) {
            $this->actingAsRole($role);
            $this->getJson('/api/admin/customers')->assertOk();
            $response = $this->postJson('/api/admin/customers', ['name' => "  Cliente {$role}  "])->assertCreated();
            $this->assertSame("Cliente {$role}", $response->json('data.name'));
            $this->assertNull($response->json('data.phone'));
        }
        $this->actingAsRole('user');
        $this->getJson('/api/admin/customers')->assertForbidden();
        $this->postJson('/api/admin/customers', ['name' => 'Sin permiso'])->assertForbidden();

        $this->actingAsRole('admin');
        $first = $this->postJson('/api/admin/customers', [
            'name' => 'Ana Principal', 'phone' => '+57 300 111 2233',
            'email' => ' ANA@EXAMPLE.COM ', 'document' => ' DOC-100 ',
        ])->assertCreated()->json('data');
        $this->postJson('/api/admin/customers', ['name' => 'Ana Duplicada', 'phone' => '+57 300 111 2233'])->assertCreated();
        $this->assertDatabaseCount('customers', 3);
        $this->assertDatabaseHas('customers', ['id' => $first['id'], 'email' => 'ana@example.com', 'document' => 'DOC-100', 'phone_normalized' => '573001112233']);

        foreach (['Ana Principal', '3001112233', 'ANA@example.com', 'DOC-100'] as $search) {
            $this->getJson('/api/admin/customers?search='.urlencode($search))->assertOk()->assertJsonFragment(['id' => $first['id']]);
        }
        $this->patchJson("/api/admin/customers/{$first['id']}", ['name' => 'Ana Actualizada', 'is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);
        $this->deleteJson("/api/admin/customers/{$first['id']}")->assertMethodNotAllowed();
    }

    public function test_customer_vehicle_crud_hierarchy_normalization_vin_identity_ownership_and_sales_access(): void
    {
        $this->actingAsRole('admin');
        $customer = $this->customer('Propietario A');
        $otherCustomer = $this->customer('Propietario B');
        [$brand, $model, $version] = $this->vehicleCatalog();
        $otherBrand = VehicleBrand::create(['name' => 'Marca B', 'slug' => 'marca-b', 'is_active' => true]);
        $otherModel = VehicleModel::create(['vehicle_brand_id' => $otherBrand->id, 'name' => 'Modelo B', 'slug' => 'modelo-b', 'is_active' => true]);
        $otherVersion = VehicleVersion::create(['vehicle_model_id' => $otherModel->id, 'name' => 'Otra', 'year_from' => 2010, 'year_to' => 2020, 'is_active' => true]);

        $vehicle = $this->postJson("/api/admin/customers/{$customer->id}/vehicles", [
            'vehicle_brand_id' => $brand->id, 'vehicle_model_id' => $model->id,
            'vehicle_version_id' => $version->id, 'year' => 2015,
            'plate' => ' abc-123 ', 'vin' => ' vin 0001 ', 'color' => 'Negro',
        ])->assertCreated()->assertJsonPath('data.plate', 'ABC123')->assertJsonPath('data.vin', 'VIN0001')->json('data');

        $this->postJson("/api/admin/customers/{$customer->id}/vehicles", ['nickname' => 'Segundo'])->assertCreated();
        $this->assertSame(2, $customer->vehicles()->count());
        $this->postJson("/api/admin/customers/{$otherCustomer->id}/vehicles", ['vin' => 'vin0001'])->assertUnprocessable()->assertJsonValidationErrors('vin');
        $this->postJson("/api/admin/customers/{$customer->id}/vehicles", ['vehicle_brand_id' => $brand->id, 'vehicle_model_id' => $otherModel->id])->assertUnprocessable()->assertJsonValidationErrors('vehicle_model_id');
        $this->postJson("/api/admin/customers/{$customer->id}/vehicles", ['vehicle_brand_id' => $brand->id, 'vehicle_model_id' => $model->id, 'vehicle_version_id' => $otherVersion->id])->assertUnprocessable()->assertJsonValidationErrors('vehicle_version_id');
        $this->postJson("/api/admin/customers/{$customer->id}/vehicles", ['vehicle_brand_id' => $brand->id, 'vehicle_model_id' => $model->id, 'vehicle_version_id' => $version->id, 'year' => 2020])->assertUnprocessable()->assertJsonValidationErrors('year');

        $this->getJson("/api/admin/customers/{$otherCustomer->id}/vehicles/{$vehicle['id']}")->assertNotFound();
        $this->patchJson("/api/admin/customers/{$otherCustomer->id}/vehicles/{$vehicle['id']}", ['color' => 'Azul'])->assertNotFound();
        $this->patchJson("/api/admin/customers/{$customer->id}/vehicles/{$vehicle['id']}", ['color' => 'Gris', 'is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false)->assertJsonPath('data.color', 'Gris');
    }

    public function test_order_integration_preserves_customer_and_vehicle_snapshots_and_existing_flows(): void
    {
        $this->actingAsRole('admin');
        $customer = $this->customer('Juan Pérez', ['phone' => '3001002000', 'email' => 'juan@example.com', 'city' => 'Barranquilla']);
        $otherCustomer = $this->customer('Otra persona');
        [$brand, $model, $version] = $this->vehicleCatalog();
        $vehicle = CustomerVehicle::create([
            'customer_id' => $customer->id, 'vehicle_brand_id' => $brand->id,
            'vehicle_model_id' => $model->id, 'vehicle_version_id' => $version->id,
            'year' => 2015, 'plate' => 'ABC123', 'vin' => 'VIN001', 'color' => 'Negro',
        ]);
        $product = $this->product();
        $variant = ProductVariant::create([
            'product_id' => $product->id, 'name' => '8/128', 'normalized_name' => '8/128',
            'sku' => 'VAR-CUSTOMER', 'price' => 120000,
            'is_default' => true, 'is_active' => true, 'is_visible' => true,
        ]);
        $stock = $this->inventoryStock($variant, 10);

        $orderData = $this->postJson('/api/admin/orders', [
            'branch_id' => $this->branch()->id,
            'customer_id' => $customer->id, 'customer_vehicle_id' => $vehicle->id,
            'customer_name' => 'Nombre contradictorio', 'vehicle_plate' => 'OTRA',
            'items' => [['product_id' => $product->id, 'product_variant_id' => $variant->id, 'quantity' => 1]],
            'payment' => ['amount' => 50000, 'method' => 'cash'],
        ])->assertCreated()->assertJsonPath('data.customer_id', $customer->id)
            ->assertJsonPath('data.customer_vehicle_id', $vehicle->id)
            ->assertJsonPath('data.customer_name', 'Juan Pérez')
            ->assertJsonPath('data.vehicle_plate', 'ABC123')->json('data');

        $order = Order::findOrFail($orderData['id']);
        $this->assertSame('partial', $order->payment_status);
        $this->assertSame(9, $stock->fresh()->quantity);
        $customer->update(['name' => 'Juan Nuevo', 'phone' => '999']);
        $vehicle->update(['plate' => 'NEW999', 'color' => 'Blanco']);
        $this->assertSame('Juan Pérez', $order->fresh()->customer_name);
        $this->assertSame('3001002000', $order->fresh()->customer_phone);
        $this->assertSame('ABC123', $order->fresh()->vehicle_plate);
        $this->assertSame('Negro', $order->fresh()->vehicle_color);

        $this->postJson('/api/admin/orders', [
            'branch_id' => $this->branch()->id,
            'customer_id' => $otherCustomer->id,
            'customer_vehicle_id' => $vehicle->id,
            'items' => [['product_id' => $product->id, 'product_variant_id' => $variant->id, 'quantity' => 1]],
        ])
            ->assertUnprocessable()->assertJsonValidationErrors('customer_vehicle_id');

        $count = Customer::count();
        $vehicleCount = CustomerVehicle::count();
        $this->postJson('/api/admin/orders', [
            'branch_id' => $this->branch()->id,
            'items' => [['product_id' => $product->id, 'product_variant_id' => $variant->id, 'quantity' => 1]],
        ])->assertCreated()->assertJsonPath('data.customer_id', null);
        $this->postJson('/api/admin/orders', [
            'branch_id' => $this->branch()->id,
            'customer_name' => 'Ad hoc',
            'customer_phone' => '123',
            'items' => [['product_id' => $product->id, 'product_variant_id' => $variant->id, 'quantity' => 1]],
        ])->assertCreated()->assertJsonPath('data.customer_name', 'Ad hoc');
        $this->postJson('/api/admin/orders', [
            'branch_id' => $this->branch()->id,
            'vehicle_brand_id' => $brand->id,
            'vehicle_model_id' => $model->id,
            'vehicle_plate' => 'ADHOC',
            'items' => [['product_id' => $product->id, 'product_variant_id' => $variant->id, 'quantity' => 1]],
        ])->assertCreated()->assertJsonPath('data.customer_vehicle_id', null);
        $this->assertSame($count, Customer::count());
        $this->assertSame($vehicleCount, CustomerVehicle::count());

        Order::create(['order_number' => 'LEGACY-CUSTOMER', 'origin' => 'ecommerce', 'customer_name' => 'Legacy', 'subtotal' => 1, 'discount_total' => 0, 'total' => 1, 'status' => 'pending', 'payment_status' => 'unpaid']);
        $this->getJson('/api/admin/orders/'.Order::where('order_number', 'LEGACY-CUSTOMER')->value('id'))->assertOk()->assertJsonPath('data.customer_id', null);
    }

    public function test_customer_and_vehicle_histories_are_scoped_paginated_and_recent_first(): void
    {
        $this->actingAsRole('admin');
        $first = $this->customer('Primero');
        $second = $this->customer('Segundo');
        $vehicleA = CustomerVehicle::create(['customer_id' => $first->id, 'plate' => 'AAA111']);
        $vehicleB = CustomerVehicle::create(['customer_id' => $first->id, 'plate' => 'BBB222']);
        $old = $this->order('HIST-OLD', $first, $vehicleA);
        $old->forceFill(['created_at' => now()->subDay()])->save();
        $new = $this->order('HIST-NEW', $first, $vehicleB);
        $this->order('HIST-OTHER', $second);

        $this->getJson("/api/admin/customers/{$first->id}/orders")
            ->assertOk()->assertJsonPath('data.data.0.id', $new->id)->assertJsonMissing(['order_number' => 'HIST-OTHER']);
        $this->getJson("/api/admin/customers/{$first->id}/vehicles/{$vehicleA->id}/orders")
            ->assertOk()->assertJsonPath('data.data.0.id', $old->id)->assertJsonMissing(['order_number' => 'HIST-NEW']);

        $this->actingAsRole('viewer');
        $this->getJson("/api/admin/customers/{$first->id}/orders")->assertForbidden();
        $this->getJson("/api/admin/customers/{$first->id}/vehicles/{$vehicleA->id}/orders")->assertForbidden();
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function customer(string $name, array $values = []): Customer
    {
        return Customer::create([...['name' => $name, 'is_active' => true], ...$values]);
    }

    private function vehicleCatalog(): array
    {
        $brand = VehicleBrand::create(['name' => 'Marca A'.uniqid(), 'slug' => 'marca-a-'.uniqid(), 'is_active' => true]);
        $model = VehicleModel::create(['vehicle_brand_id' => $brand->id, 'name' => 'Modelo A', 'slug' => 'modelo-a-'.uniqid(), 'is_active' => true]);
        $version = VehicleVersion::create(['vehicle_model_id' => $model->id, 'name' => 'Fase', 'year_from' => 2014, 'year_to' => 2018, 'is_active' => true]);

        return [$brand, $model, $version];
    }

    private function product(): Product
    {
        return Product::create(['name' => 'Producto cliente', 'slug' => 'producto-'.uniqid(), 'sku' => 'P-'.uniqid(), 'price' => 100000, 'cost_price' => 40000, 'is_active' => true, 'is_visible' => true]);
    }

    private function branch(): Branch
    {
        return Branch::firstOrCreate(
            ['code' => 'CUSTOMER'],
            ['slug' => 'clientes', 'name' => 'Sede clientes', 'city' => 'Barranquilla', 'is_active' => true],
        );
    }

    private function inventoryStock(ProductVariant $variant, int $quantity): InventoryStock
    {
        $item = InventoryItem::firstOrCreate([
            'product_id' => null,
            'product_variant_id' => $variant->id,
        ]);

        return InventoryStock::updateOrCreate(
            ['branch_id' => $this->branch()->id, 'inventory_item_id' => $item->id],
            ['quantity' => $quantity, 'minimum_quantity' => 0],
        );
    }

    private function order(string $number, Customer $customer, ?CustomerVehicle $vehicle = null): Order
    {
        return Order::create([
            'order_number' => $number, 'origin' => 'crm', 'customer_id' => $customer->id,
            'customer_vehicle_id' => $vehicle?->id, 'customer_name' => $customer->name,
            'vehicle_plate' => $vehicle?->plate, 'subtotal' => 100, 'discount_total' => 0,
            'total' => 100, 'status' => 'completed', 'payment_status' => 'paid',
        ]);
    }
}
