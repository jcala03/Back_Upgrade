<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Models\UserCapability;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class MultibranchCommercialSalesPhaseThreeTest extends TestCase
{
    use RefreshDatabase;

    public function createApplication()
    {
        $connection = trim((string) getenv('TEST_DB_CONNECTION'));
        $database = trim((string) getenv('TEST_DB_DATABASE'));

        if ($connection !== 'mysql' || $database === '') {
            throw new RuntimeException('MultibranchCommercialSalesPhaseThreeTest requiere TEST_DB_CONNECTION=mysql y TEST_DB_DATABASE explícita.');
        }

        if (strcasecmp($database, 'upgrade') === 0) {
            throw new RuntimeException('MultibranchCommercialSalesPhaseThreeTest no puede ejecutarse contra la base principal upgrade.');
        }

        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $app['config']->set('database.default', $connection);
        $app['config']->set("database.connections.{$connection}.database", $database);

        if (strcasecmp((string) $app['config']->get("database.connections.{$connection}.database"), 'upgrade') === 0) {
            throw new RuntimeException('La base configurada para MultibranchCommercialSalesPhaseThreeTest no puede ser upgrade.');
        }

        return $app;
    }

    public function test_admin_sale_requires_active_branch_and_matching_active_seller(): void
    {
        $this->admin();
        $branch = $this->branch();
        $otherBranch = $this->branch('BOG');
        $seller = Employee::create([
            'branch_id' => $otherBranch->id,
            'name' => 'Vendedor Bogotá',
            'job_title' => 'Asesor',
            'is_active' => true,
        ]);
        $inactiveSeller = Employee::create([
            'branch_id' => $branch->id,
            'name' => 'Vendedor inactivo',
            'job_title' => 'Asesor',
            'is_active' => false,
        ]);
        $product = $this->product();

        $this->postJson('/api/admin/orders', $this->salePayload($product))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('branch_id');
        $this->postJson('/api/admin/orders', [
            ...$this->salePayload($product),
            'branch_id' => $branch->id,
            'sales_employee_id' => $seller->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('sales_employee_id');
        $this->postJson('/api/admin/orders', [
            ...$this->salePayload($product),
            'branch_id' => $branch->id,
            'sales_employee_id' => $inactiveSeller->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('sales_employee_id');

        $branch->update(['is_active' => false]);
        $this->postJson('/api/admin/orders', [
            ...$this->salePayload($product),
            'branch_id' => $branch->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('branch_id');
    }

    public function test_confirmed_sale_moves_only_authoritative_branch_stock_and_cancel_restores_inactive_historical_branch_once(): void
    {
        $this->admin();
        $branch = $this->branch();
        $product = $this->product();
        $stock = $this->stock($branch, $product, 5);

        $orderId = $this->postJson('/api/admin/orders', [
            ...$this->salePayload($product, 2),
            'branch_id' => $branch->id,
        ])->assertCreated()
            ->assertJsonPath('data.branch_id', $branch->id)
            ->assertJsonPath('data.status', Order::STATUS_CONFIRMED)
            ->json('data.id');

        $this->assertSame(3, $stock->fresh()->quantity);
        $this->assertDatabaseHas('inventory_movements', [
            'branch_id' => $branch->id,
            'type' => InventoryMovement::TYPE_SALE,
            'reference_type' => Order::class,
            'reference_id' => $orderId,
            'quantity_delta' => -2,
        ]);

        $branch->update(['is_active' => false]);
        $this->postJson("/api/admin/orders/{$orderId}/status", [
            'status' => Order::STATUS_CANCELLED,
        ])->assertOk();
        $this->postJson("/api/admin/orders/{$orderId}/status", [
            'status' => Order::STATUS_CANCELLED,
        ])->assertOk();

        $this->assertSame(5, $stock->fresh()->quantity);
        $this->assertDatabaseCount('inventory_movements', 2);
        $this->assertDatabaseHas('inventory_movements', [
            'branch_id' => $branch->id,
            'type' => InventoryMovement::TYPE_SALE_REVERSAL,
            'reference_type' => Order::class,
            'reference_id' => $orderId,
            'quantity_delta' => 2,
        ]);
    }

    public function test_branch_availability_is_checked_at_confirmation_and_other_branch_stock_is_never_used(): void
    {
        $this->admin();
        $branch = $this->branch();
        $otherBranch = $this->branch('CTG');
        $product = $this->product();
        $stock = $this->stock($branch, $product, 1);
        $otherStock = $this->stock($otherBranch, $product, 20);

        $orderId = $this->withHeader('Idempotency-Key', __METHOD__)->postJson('/api/orders', $this->publicPayload($product, 2))
            ->assertCreated()
            ->assertJsonPath('data.branch_id', null)
            ->json('data.id');

        $this->postJson("/api/admin/orders/{$orderId}/status", [
            'status' => Order::STATUS_CONFIRMED,
        ])->assertUnprocessable()->assertJsonValidationErrors('branch_id');
        $this->postJson("/api/admin/orders/{$orderId}/status", [
            'status' => Order::STATUS_CONFIRMED,
            'branch_id' => null,
        ])->assertUnprocessable()->assertJsonValidationErrors('branch_id');
        $this->postJson("/api/admin/orders/{$orderId}/status", [
            'status' => Order::STATUS_CONFIRMED,
            'branch_id' => $branch->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('quantity');

        $this->assertSame(1, $stock->fresh()->quantity);
        $this->assertSame(20, $otherStock->fresh()->quantity);
        $this->assertSame(Order::STATUS_PENDING, Order::findOrFail($orderId)->status);
    }

    public function test_frozen_simple_inventory_identity_survives_later_catalog_variant_changes(): void
    {
        $this->admin();
        $branch = $this->branch();
        $product = $this->product();
        $stock = $this->stock($branch, $product, 2);

        $orderId = $this->postJson('/api/admin/orders', [
            ...$this->salePayload($product),
            'branch_id' => $branch->id,
        ])->assertCreated()->json('data.id');
        $this->assertSame(1, $stock->fresh()->quantity);

        ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Catálogo posterior',
            'normalized_name' => 'catalogo posterior',
            'sku' => 'POST-'.uniqid(),
            'price' => 120000,
            'cost_price' => 30000,
            'is_default' => true,
            'is_active' => true,
            'is_visible' => true,
        ]);

        $this->postJson("/api/admin/orders/{$orderId}/status", [
            'status' => Order::STATUS_CANCELLED,
        ])->assertOk();

        $this->assertSame(2, $stock->fresh()->quantity);
        $this->assertDatabaseHas('inventory_movements', [
            'branch_id' => $branch->id,
            'inventory_item_id' => $stock->inventory_item_id,
            'type' => InventoryMovement::TYPE_SALE_REVERSAL,
            'reference_id' => $orderId,
        ]);
    }

    public function test_confirm_idempotency_never_changes_branch_or_seller_snapshot(): void
    {
        $this->admin();
        $branch = $this->branch();
        $otherBranch = $this->branch('MED');
        $seller = Employee::create(['branch_id' => $branch->id, 'name' => 'Vendedora', 'job_title' => 'Asesora', 'is_active' => true]);
        $otherSeller = Employee::create(['branch_id' => $otherBranch->id, 'name' => 'Otro', 'job_title' => 'Asesor', 'is_active' => true]);
        $product = $this->product();
        $this->stock($branch, $product, 4);

        $orderId = $this->postJson('/api/admin/orders', [
            ...$this->salePayload($product),
            'branch_id' => $branch->id,
            'sales_employee_id' => $seller->id,
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/admin/orders/{$orderId}/status", [
            'status' => Order::STATUS_CONFIRMED,
            'branch_id' => $otherBranch->id,
            'sales_employee_id' => $otherSeller->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['branch_id', 'sales_employee_id']);

        $order = Order::findOrFail($orderId);
        $this->assertSame($branch->id, $order->branch_id);
        $this->assertSame($seller->id, $order->sales_employee_id);
        $this->assertDatabaseCount('inventory_movements', 1);
    }

    public function test_variant_stock_is_independent_and_service_lines_never_move_inventory(): void
    {
        $this->admin();
        $branch = $this->branch();
        $product = $this->product();
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => '12 pulgadas',
            'normalized_name' => '12 pulgadas',
            'sku' => 'VAR-'.uniqid(),
            'price' => 150000,
            'cost_price' => 50000,
            'is_default' => true,
            'is_active' => true,
            'is_visible' => true,
        ]);
        $stock = $this->stock($branch, $product, 4, $variant);
        $category = ServiceCategory::create([
            'name' => 'Instalación '.uniqid(),
            'slug' => 'instalacion-'.uniqid(),
            'is_active' => true,
        ]);
        $service = Service::create([
            'service_category_id' => $category->id,
            'name' => 'Instalación profesional',
            'slug' => 'instalacion-profesional-'.uniqid(),
            'price' => 80000,
            'cost' => 30000,
            'estimated_duration_minutes' => 60,
            'is_active' => true,
        ]);

        $orderId = $this->postJson('/api/admin/orders', [
            'branch_id' => $branch->id,
            'items' => [
                ['product_id' => $product->id, 'product_variant_id' => $variant->id, 'quantity' => 2],
                ['item_type' => 'service', 'service_id' => $service->id, 'quantity' => 1],
            ],
        ])->assertCreated()->json('data.id');

        $this->assertSame(2, $stock->fresh()->quantity);
        $this->assertSame(1, InventoryMovement::where('reference_id', $orderId)->count());
        $this->assertDatabaseHas('order_items', [
            'order_id' => $orderId,
            'item_type' => 'service',
            'service_id' => $service->id,
        ]);
    }

    public function test_my_sales_are_derived_scoped_private_and_support_owned_lifecycle_and_payment(): void
    {
        $branch = $this->branch();
        [$user, $employee] = $this->commercialUser($branch, [
            UserCapability::ORDERS_VIEW_OWN,
            UserCapability::ORDERS_CREATE_OWN,
            UserCapability::ORDERS_CONFIRM_OWN,
            UserCapability::ORDERS_COMPLETE_OWN,
            UserCapability::PAYMENTS_CREATE_OWN,
        ]);
        $product = $this->product(price: 100000, cost: 40000);
        $this->stock($branch, $product, 3);
        Sanctum::actingAs($user);

        $this->postJson('/api/my/sales', [
            ...$this->salePayload($product),
            'branch_id' => $branch->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('branch_id');

        $response = $this->postJson('/api/my/sales', $this->salePayload($product))
            ->assertCreated()
            ->assertJsonPath('data.status', Order::STATUS_PENDING)
            ->assertJsonPath('data.branch.id', $branch->id)
            ->assertJsonPath('data.sales_employee.id', $employee->id);
        $orderId = $response->json('data.id');
        $this->assertArrayNotHasKey('unit_cost', $response->json('data.items.0'));

        $this->getJson('/api/my/sales')->assertOk()
            ->assertJsonPath('data.employee_linked', true)
            ->assertJsonPath('data.sales.data.0.id', $orderId);
        $this->postJson("/api/my/sales/{$orderId}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', Order::STATUS_CONFIRMED);
        $payment = $this->postJson("/api/my/sales/{$orderId}/payments", [
            'amount' => 50000,
            'method' => 'cash',
        ])->assertCreated();
        $this->assertArrayNotHasKey('created_by', $payment->json('data'));
        $this->assertArrayNotHasKey('metadata', $payment->json('data'));
        $this->assertArrayNotHasKey('notes', $payment->json('data'));
        $this->assertArrayNotHasKey('transaction_id', $payment->json('data'));
        $this->postJson("/api/my/sales/{$orderId}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', Order::STATUS_COMPLETED);

        $otherBranch = $this->branch('CAL');
        [$otherUser] = $this->commercialUser($otherBranch, [UserCapability::ORDERS_VIEW_OWN]);
        Sanctum::actingAs($otherUser);
        $this->getJson("/api/my/sales/{$orderId}")->assertNotFound();
    }

    public function test_my_sales_capabilities_are_independent_and_missing_employee_list_is_safe(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER, 'is_active' => true]);
        Sanctum::actingAs($user);

        $this->getJson('/api/my/sales')->assertForbidden();
        UserCapability::create([
            'user_id' => $user->id,
            'capability' => UserCapability::ORDERS_VIEW_OWN,
        ]);
        $user->unsetRelation('capabilities');

        $this->getJson('/api/my/sales')->assertOk()
            ->assertJsonPath('data.employee_linked', false)
            ->assertJsonPath('data.sales', []);
    }

    public function test_user_without_employee_cannot_show_a_sellerless_sale(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER, 'is_active' => true]);
        UserCapability::create([
            'user_id' => $user->id,
            'capability' => UserCapability::ORDERS_VIEW_OWN,
        ]);
        $order = Order::create([
            'order_number' => 'LEGACY-'.uniqid(),
            'origin' => Order::ORIGIN_ECOMMERCE,
            'subtotal' => 100000,
            'discount_total' => 0,
            'total' => 100000,
            'status' => Order::STATUS_PENDING,
            'payment_status' => Order::PAYMENT_UNPAID,
        ]);
        Sanctum::actingAs($user);

        $this->getJson("/api/my/sales/{$order->id}")->assertNotFound();
    }

    public function test_my_sale_mutations_require_active_user_employee_and_branch_context(): void
    {
        $product = $this->product();

        $activeBranch = $this->branch('ACT');
        [$inactiveAccount] = $this->commercialUser($activeBranch, [
            UserCapability::ORDERS_CREATE_OWN,
        ]);
        $inactiveAccount->update(['is_active' => false]);
        Sanctum::actingAs($inactiveAccount);
        $this->postJson('/api/my/sales', $this->salePayload($product))
            ->assertForbidden();

        $withoutEmployee = User::factory()->create(['role' => User::ROLE_USER, 'is_active' => true]);
        UserCapability::create([
            'user_id' => $withoutEmployee->id,
            'capability' => UserCapability::ORDERS_CREATE_OWN,
        ]);
        Sanctum::actingAs($withoutEmployee);
        $this->postJson('/api/my/sales', $this->salePayload($product))
            ->assertUnprocessable()->assertJsonValidationErrors('employee');

        $withoutBranch = User::factory()->create(['role' => User::ROLE_USER, 'is_active' => true]);
        UserCapability::create([
            'user_id' => $withoutBranch->id,
            'capability' => UserCapability::ORDERS_CREATE_OWN,
        ]);
        Employee::create([
            'user_id' => $withoutBranch->id,
            'name' => 'Sin sede',
            'job_title' => 'Asesor',
            'is_active' => true,
        ]);
        Sanctum::actingAs($withoutBranch);
        $this->postJson('/api/my/sales', $this->salePayload($product))
            ->assertUnprocessable()->assertJsonValidationErrors('branch_id');

        $branch = $this->branch();
        [$inactiveUser, $inactiveEmployee] = $this->commercialUser($branch, [
            UserCapability::ORDERS_CREATE_OWN,
        ]);
        $inactiveEmployee->update(['is_active' => false]);
        Sanctum::actingAs($inactiveUser);
        $this->postJson('/api/my/sales', $this->salePayload($product))
            ->assertUnprocessable()->assertJsonValidationErrors('employee');

        [$inactiveBranchUser] = $this->commercialUser($branch, [
            UserCapability::ORDERS_CREATE_OWN,
        ]);
        $branch->update(['is_active' => false]);
        Sanctum::actingAs($inactiveBranchUser);
        $this->postJson('/api/my/sales', $this->salePayload($product))
            ->assertUnprocessable()->assertJsonValidationErrors('branch_id');
    }

    public function test_my_sale_mutations_require_independent_capabilities_and_foreign_sales_are_not_disclosed(): void
    {
        $branch = $this->branch();
        [$owner, $ownerEmployee] = $this->commercialUser($branch, [UserCapability::ORDERS_VIEW_OWN]);
        $product = $this->product();
        $order = Order::create([
            'order_number' => 'OWN-'.uniqid(),
            'branch_id' => $branch->id,
            'sales_employee_id' => $ownerEmployee->id,
            'origin' => Order::ORIGIN_CRM,
            'subtotal' => 100000,
            'discount_total' => 0,
            'total' => 100000,
            'status' => Order::STATUS_PENDING,
            'payment_status' => Order::PAYMENT_UNPAID,
        ]);
        Sanctum::actingAs($owner);

        $this->postJson('/api/my/sales', $this->salePayload($product))->assertForbidden();
        $this->postJson("/api/my/sales/{$order->id}/confirm")->assertForbidden();
        $this->postJson("/api/my/sales/{$order->id}/complete")->assertForbidden();
        $this->postJson("/api/my/sales/{$order->id}/payments", ['amount' => 1, 'method' => 'cash'])->assertForbidden();

        $otherBranch = $this->branch('PER');
        [$other] = $this->commercialUser($otherBranch, [
            UserCapability::ORDERS_VIEW_OWN,
            UserCapability::ORDERS_CONFIRM_OWN,
            UserCapability::ORDERS_COMPLETE_OWN,
            UserCapability::PAYMENTS_CREATE_OWN,
        ]);
        Sanctum::actingAs($other);
        $this->getJson("/api/my/sales/{$order->id}")->assertNotFound();
        $this->postJson("/api/my/sales/{$order->id}/confirm")->assertNotFound();
        $this->postJson("/api/my/sales/{$order->id}/complete")->assertNotFound();
        $this->postJson("/api/my/sales/{$order->id}/payments", ['amount' => 1, 'method' => 'cash'])->assertNotFound();
    }

    public function test_my_sale_rejects_seller_spoof_branch_move_and_branch_specific_shortage(): void
    {
        $branch = $this->branch();
        $otherBranch = $this->branch('SMA');
        [$user, $employee] = $this->commercialUser($branch, [
            UserCapability::ORDERS_CREATE_OWN,
            UserCapability::ORDERS_CONFIRM_OWN,
            UserCapability::ORDERS_COMPLETE_OWN,
            UserCapability::PAYMENTS_CREATE_OWN,
        ]);
        [, $otherEmployee] = $this->commercialUser($otherBranch, []);
        $product = $this->product();
        $stock = $this->stock($branch, $product, 1);
        $otherStock = $this->stock($otherBranch, $product, 20);
        Sanctum::actingAs($user);

        $this->postJson('/api/my/sales', [
            ...$this->salePayload($product, 2),
            'sales_employee_id' => $otherEmployee->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('sales_employee_id');
        $orderId = $this->postJson('/api/my/sales', $this->salePayload($product, 2))
            ->assertCreated()->json('data.id');
        $this->postJson("/api/my/sales/{$orderId}/confirm")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quantity');
        $this->assertSame(1, $stock->fresh()->quantity);
        $this->assertSame(20, $otherStock->fresh()->quantity);

        $employee->update(['branch_id' => $otherBranch->id]);
        $this->postJson("/api/my/sales/{$orderId}/confirm")
            ->assertUnprocessable()->assertJsonValidationErrors('branch_id');
        $this->postJson("/api/my/sales/{$orderId}/complete")
            ->assertUnprocessable()->assertJsonValidationErrors('branch_id');
        $this->postJson("/api/my/sales/{$orderId}/payments", ['amount' => 1, 'method' => 'cash'])
            ->assertUnprocessable()->assertJsonValidationErrors('branch_id');
    }

    public function test_my_sale_has_no_cancel_or_generic_mutation_routes_and_payment_is_completed_without_client_status(): void
    {
        $branch = $this->branch();
        [$user] = $this->commercialUser($branch, [
            UserCapability::ORDERS_CREATE_OWN,
            UserCapability::ORDERS_CONFIRM_OWN,
            UserCapability::PAYMENTS_CREATE_OWN,
        ]);
        $product = $this->product(price: 100000);
        $this->stock($branch, $product, 2);
        Sanctum::actingAs($user);
        $orderId = $this->postJson('/api/my/sales', $this->salePayload($product))
            ->assertCreated()->json('data.id');
        $this->postJson("/api/my/sales/{$orderId}/confirm")->assertOk();

        $this->patchJson("/api/my/sales/{$orderId}", ['status' => Order::STATUS_CANCELLED])->assertMethodNotAllowed();
        $this->postJson("/api/my/sales/{$orderId}/cancel")->assertNotFound();
        $this->postJson("/api/my/sales/{$orderId}/status", ['status' => Order::STATUS_CANCELLED])->assertNotFound();
        $this->postJson("/api/my/sales/{$orderId}/payments", [
            'amount' => 50000,
            'method' => 'cash',
            'status' => 'pending',
        ])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->postJson("/api/my/sales/{$orderId}/payments", [
            'amount' => 100001,
            'method' => 'cash',
        ])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->postJson("/api/my/sales/{$orderId}/payments", [
            'amount' => 50000,
            'method' => 'cash',
        ])->assertCreated()->assertJsonPath('data.status', 'completed');

        Order::findOrFail($orderId)->update(['status' => Order::STATUS_CANCELLED]);
        $this->postJson("/api/my/sales/{$orderId}/payments", [
            'amount' => 1,
            'method' => 'cash',
        ])->assertUnprocessable()->assertJsonValidationErrors('order');
    }

    public function test_transfer_dispatch_and_sale_confirmation_serialize_on_the_same_branch_stock(): void
    {
        $this->admin();
        $source = $this->branch();
        $destination = $this->branch('VUP');
        $product = $this->product();
        $stock = $this->stock($source, $product, 3);
        $orderId = $this->withHeader('Idempotency-Key', __METHOD__)->postJson('/api/orders', $this->publicPayload($product, 2))
            ->assertCreated()->json('data.id');
        $transferId = $this->postJson('/api/admin/inventory/transfers', [
            'source_branch_id' => $source->id,
            'destination_branch_id' => $destination->id,
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/admin/inventory/transfers/{$transferId}/dispatch")->assertOk();
        $this->postJson("/api/admin/orders/{$orderId}/status", [
            'status' => Order::STATUS_CONFIRMED,
            'branch_id' => $source->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('quantity');

        $this->assertSame(1, $stock->fresh()->quantity);
        $this->assertSame(Order::STATUS_PENDING, Order::findOrFail($orderId)->status);
        $this->assertDatabaseCount('inventory_movements', 1);
    }

    private function admin(): User
    {
        $user = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * @param  array<int, string>  $capabilities
     * @return array{0: User, 1: Employee}
     */
    private function commercialUser(Branch $branch, array $capabilities): array
    {
        $user = User::factory()->create(['role' => User::ROLE_USER, 'is_active' => true]);
        $employee = Employee::create([
            'user_id' => $user->id,
            'branch_id' => $branch->id,
            'name' => 'Asesor '.uniqid(),
            'job_title' => 'Asesor',
            'is_active' => true,
        ]);
        foreach ($capabilities as $capability) {
            UserCapability::create(['user_id' => $user->id, 'capability' => $capability]);
        }

        return [$user, $employee];
    }

    private function branch(string $code = 'BAQ'): Branch
    {
        return Branch::create([
            'code' => $code,
            'slug' => strtolower($code).'-'.uniqid(),
            'name' => "Sede {$code}",
            'city' => 'Ciudad',
            'is_active' => true,
        ]);
    }

    private function product(int $price = 100000, int $cost = 30000): Product
    {
        return Product::create([
            'name' => 'Producto '.uniqid(),
            'slug' => 'producto-'.uniqid(),
            'sku' => 'SKU-'.uniqid(),
            'price' => $price,
            'cost_price' => $cost,
            'is_active' => true,
            'is_visible' => true,
        ]);
    }

    private function stock(
        Branch $branch,
        Product $product,
        int $quantity,
        ?ProductVariant $variant = null,
    ): InventoryStock {
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

    private function salePayload(Product $product, int $quantity = 1): array
    {
        return [
            'customer_name' => 'Cliente de prueba',
            'items' => [[
                'product_id' => $product->id,
                'quantity' => $quantity,
            ]],
        ];
    }

    private function publicPayload(Product $product, int $quantity): array
    {
        return [
            'customer_name' => 'Cliente ecommerce',
            'customer_email' => 'cliente@example.com',
            'customer_phone' => '3000000000',
            'items' => [[
                'product_id' => $product->id,
                'quantity' => $quantity,
            ]],
        ];
    }
}
