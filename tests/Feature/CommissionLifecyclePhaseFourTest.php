<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CrmNotification;
use App\Models\Employee;
use App\Models\EmployeeCommission;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Models\UserCapability;
use App\Services\CommissionService;
use App\Services\CrmNotificationService;
use App\Services\OrderService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class CommissionLifecyclePhaseFourTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, Branch> */
    private array $branches = [];

    public function createApplication()
    {
        $connection = trim((string) getenv('TEST_DB_CONNECTION'));
        $database = trim((string) getenv('TEST_DB_DATABASE'));

        if ($connection !== 'mysql' || $database === '') {
            throw new RuntimeException('CommissionLifecyclePhaseFourTest requiere TEST_DB_CONNECTION=mysql y TEST_DB_DATABASE explícita.');
        }
        if (strcasecmp($database, 'upgrade') === 0) {
            throw new RuntimeException('CommissionLifecyclePhaseFourTest no puede ejecutarse contra la base principal upgrade.');
        }

        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $app['config']->set('database.default', $connection);
        $app['config']->set("database.connections.{$connection}.database", $database);

        if (strcasecmp((string) $app['config']->get("database.connections.{$connection}.database"), 'upgrade') === 0) {
            throw new RuntimeException('La base configurada para CommissionLifecyclePhaseFourTest no puede ser upgrade.');
        }

        return $app;
    }

    public function test_admin_direct_confirmation_generates_only_eligible_product_commission(): void
    {
        $admin = $this->admin();
        $branch = $this->branch();
        $seller = $this->employee($branch, 'Asesora BAQ');
        $eligible = $this->product(true, 50000, 'Pantalla elegible');
        $disabled = $this->product(false, null, 'Cámara sin comisión');
        $this->stock($branch, $eligible, 10);
        $this->stock($branch, $disabled, 10);
        $service = $this->service();

        $orderId = $this->postJson('/api/admin/orders', [
            'branch_id' => $branch->id,
            'sales_employee_id' => $seller->id,
            'items' => [
                ['product_id' => $eligible->id, 'quantity' => 2],
                ['product_id' => $disabled->id, 'quantity' => 1],
                ['item_type' => OrderItem::ITEM_TYPE_SERVICE, 'service_id' => $service->id, 'quantity' => 1],
            ],
        ])->assertCreated()->assertJsonPath('data.status', Order::STATUS_CONFIRMED)->json('data.id');

        $commission = EmployeeCommission::sole();
        $this->assertSame($seller->id, $commission->employee_id);
        $this->assertSame($branch->id, $commission->branch_id);
        $this->assertSame($orderId, $commission->order_id);
        $this->assertSame($eligible->id, $commission->product_id);
        $this->assertSame('Pantalla elegible', $commission->product_name_snapshot);
        $this->assertSame($eligible->sku, $commission->sku_snapshot);
        $this->assertSame(2, $commission->quantity);
        $this->assertSame(50000, $commission->unit_commission);
        $this->assertSame(100000, $commission->amount);
        $this->assertSame(EmployeeCommission::STATUS_PENDING, $commission->status);
        $this->assertNull($commission->earned_at);

        $this->postJson('/api/admin/orders', [
            'branch_id' => $branch->id,
            'items' => [['product_id' => $eligible->id, 'quantity' => 1]],
        ])->assertCreated();
        $this->assertDatabaseCount('employee_commissions', 1);

        $this->postJson("/api/admin/orders/{$orderId}/status", ['status' => Order::STATUS_CONFIRMED])->assertOk();
        $this->assertDatabaseCount('employee_commissions', 1);
        $this->assertTrue($admin->hasPermission('commissions.view'));
    }

    public function test_multiple_products_and_variant_inherit_parent_rate_with_frozen_snapshots(): void
    {
        $this->admin();
        $branch = $this->branch();
        $seller = $this->employee($branch);
        $simple = $this->product(true, 50000, 'Pantalla simple');
        $parent = $this->product(true, 50000, 'Pantalla BMW');
        $variant = $this->variant($parent, '12.3 pulgadas', 'BMW-123');
        $this->stock($branch, $simple, 10);
        $this->stock($branch, $parent, 10, $variant);

        $this->postJson('/api/admin/orders', [
            'branch_id' => $branch->id,
            'sales_employee_id' => $seller->id,
            'items' => [
                ['product_id' => $simple->id, 'quantity' => 3],
                ['product_id' => $parent->id, 'product_variant_id' => $variant->id, 'quantity' => 2],
            ],
        ])->assertCreated();

        $this->assertDatabaseCount('employee_commissions', 2);
        $this->assertDatabaseHas('employee_commissions', [
            'product_id' => $simple->id,
            'product_variant_id' => null,
            'quantity' => 3,
            'unit_commission' => 50000,
            'amount' => 150000,
        ]);
        $this->assertDatabaseHas('employee_commissions', [
            'product_id' => $parent->id,
            'product_variant_id' => $variant->id,
            'product_name_snapshot' => 'Pantalla BMW',
            'variant_name_snapshot' => '12.3 pulgadas',
            'sku_snapshot' => 'BMW-123',
            'quantity' => 2,
            'unit_commission' => 50000,
            'amount' => 100000,
        ]);
    }

    public function test_my_sale_creates_pending_only_when_it_is_confirmed(): void
    {
        $branch = $this->branch();
        $user = User::factory()->create(['role' => User::ROLE_USER, 'is_active' => true]);
        $seller = $this->employee($branch, 'Asesor personal', $user);
        foreach ([UserCapability::ORDERS_CREATE_OWN, UserCapability::ORDERS_CONFIRM_OWN] as $capability) {
            UserCapability::create(['user_id' => $user->id, 'capability' => $capability]);
        }
        $product = $this->product(true, 50000);
        $this->stock($branch, $product, 5);
        Sanctum::actingAs($user);

        $orderId = $this->postJson('/api/my/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ])->assertCreated()->assertJsonPath('data.status', Order::STATUS_PENDING)->json('data.id');
        $this->assertDatabaseCount('employee_commissions', 0);
        $product->update(['commission_amount' => 60000]);

        $this->postJson("/api/my/sales/{$orderId}/confirm")
            ->assertOk()->assertJsonPath('data.status', Order::STATUS_CONFIRMED);
        $this->postJson("/api/my/sales/{$orderId}/confirm")
            ->assertOk()->assertJsonPath('data.status', Order::STATUS_CONFIRMED);
        $this->assertDatabaseHas('employee_commissions', [
            'employee_id' => $seller->id,
            'order_id' => $orderId,
            'unit_commission' => 60000,
            'amount' => 120000,
            'status' => EmployeeCommission::STATUS_PENDING,
        ]);
        $this->assertSame(1, EmployeeCommission::where('order_id', $orderId)->count());
        $this->assertDatabaseMissing('crm_notifications', [
            'user_id' => $user->id,
            'type' => CrmNotification::TYPE_COMMISSION_EARNED,
        ]);
    }

    public function test_quotation_conversion_uses_the_frozen_seller_and_branch(): void
    {
        $this->admin();
        $branch = $this->branch();
        $seller = $this->employee($branch, 'Cotizador');
        $product = $this->product(true, 50000);
        $this->stock($branch, $product, 5);

        $quotationId = $this->postJson('/api/admin/quotations', [
            'branch_id' => $branch->id,
            'sales_employee_id' => $seller->id,
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ])->assertCreated()->json('data.id');

        $orderId = $this->postJson("/api/admin/quotations/{$quotationId}/convert")
            ->assertCreated()->json('data.order_id');
        $this->assertDatabaseHas('employee_commissions', [
            'employee_id' => $seller->id,
            'branch_id' => $branch->id,
            'order_id' => $orderId,
            'amount' => 100000,
            'status' => EmployeeCommission::STATUS_PENDING,
        ]);
        $this->postJson("/api/admin/quotations/{$quotationId}/convert")->assertUnprocessable();
        $this->assertSame(1, Order::where('quotation_id', $quotationId)->count());
        $this->assertSame(1, EmployeeCommission::where('order_id', $orderId)->count());
    }

    public function test_rate_changes_only_affect_later_confirmations(): void
    {
        $this->admin();
        $branch = $this->branch();
        $seller = $this->employee($branch);
        $product = $this->product(true, 50000);
        $this->stock($branch, $product, 5);

        $firstOrder = $this->adminSale($branch, $seller, $product);
        $originalName = $product->name;
        $originalSku = $product->sku;
        $product->update([
            'commission_amount' => 60000,
            'name' => 'Producto actualizado',
            'sku' => 'SKU-NUEVO',
        ]);
        $secondOrder = $this->adminSale($branch, $seller, $product);

        $first = EmployeeCommission::where('order_id', $firstOrder)->firstOrFail();
        $second = EmployeeCommission::where('order_id', $secondOrder)->firstOrFail();
        $this->assertSame(50000, $first->unit_commission);
        $this->assertSame(60000, $second->unit_commission);
        $this->assertSame(50000, $first->amount);
        $this->assertSame(60000, $second->amount);
        $this->assertSame($originalName, $first->product_name_snapshot);
        $this->assertSame($originalSku, $first->sku_snapshot);
        $this->assertSame('Producto actualizado', $second->product_name_snapshot);
        $this->assertSame('SKU-NUEVO', $second->sku_snapshot);
    }

    public function test_complete_earns_without_payment_notifies_once_and_is_idempotent(): void
    {
        $admin = $this->admin();
        $branch = $this->branch();
        $recipient = User::factory()->create(['role' => User::ROLE_USER, 'is_active' => true]);
        $seller = $this->employee($branch, 'Asesora notificada', $recipient);
        $product = $this->product(true, 50000);
        $this->stock($branch, $product, 5);
        $orderId = $this->adminSale($branch, $seller, $product, 2);

        $this->assertDatabaseCount('payments', 0);
        $this->postJson("/api/admin/orders/{$orderId}/status", ['status' => Order::STATUS_COMPLETED])
            ->assertOk()->assertJsonPath('data.status', Order::STATUS_COMPLETED);

        $order = Order::findOrFail($orderId);
        $commission = EmployeeCommission::where('order_id', $orderId)->firstOrFail();
        $this->assertSame(EmployeeCommission::STATUS_EARNED, $commission->status);
        $this->assertNotNull($commission->earned_at);
        $this->assertSame($order->completed_at?->getTimestamp(), $commission->earned_at?->getTimestamp());
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseHas('crm_notifications', [
            'user_id' => $recipient->id,
            'type' => CrmNotification::TYPE_COMMISSION_EARNED,
            'severity' => CrmNotification::SEVERITY_SUCCESS,
            'reference_type' => 'order',
            'reference_id' => $orderId,
            'dedupe_key' => "commission_earned:{$commission->id}",
        ]);
        $notification = CrmNotification::where('user_id', $recipient->id)->sole();
        $this->assertSame([
            'commission_id' => $commission->id,
            'order_id' => $orderId,
            'order_number' => $order->order_number,
            'amount' => 100000,
        ], $notification->data);
        $this->assertArrayNotHasKey('customer_email', $notification->data);
        $this->assertArrayNotHasKey('customer_phone', $notification->data);
        $this->assertArrayNotHasKey('unit_cost', $notification->data);
        $this->assertArrayNotHasKey('customer_document', $notification->data);
        $this->assertArrayNotHasKey('customer_address', $notification->data);
        $this->assertArrayNotHasKey('vehicle_vin', $notification->data);
        $this->assertContains(CrmNotification::TYPE_COMMISSION_EARNED, CrmNotification::types());

        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/orders/{$orderId}/status", ['status' => Order::STATUS_COMPLETED])->assertOk();
        $this->assertDatabaseCount('employee_commissions', 1);
        $this->assertDatabaseCount('crm_notifications', 1);
        $this->assertSame($commission->earned_at?->getTimestamp(), $commission->fresh()->earned_at?->getTimestamp());
        $this->assertSame(100000, $commission->fresh()->amount);
    }

    public function test_cancel_voids_pending_restores_branch_stock_once_and_completed_is_terminal(): void
    {
        $admin = $this->admin();
        $branch = $this->branch();
        $seller = $this->employee($branch);
        $product = $this->product(true, 50000);
        $stock = $this->stock($branch, $product, 5);
        $orderId = $this->adminSale($branch, $seller, $product, 2);
        $this->assertSame(3, $stock->fresh()->quantity);

        $this->postJson("/api/admin/orders/{$orderId}/status", [
            'status' => Order::STATUS_CANCELLED,
            'reason' => 'Cliente desistió',
        ])->assertOk();
        $commission = EmployeeCommission::where('order_id', $orderId)->firstOrFail();
        $this->assertSame(EmployeeCommission::STATUS_VOIDED, $commission->status);
        $this->assertNotNull($commission->voided_at);
        $this->assertSame($admin->id, $commission->voided_by);
        $this->assertSame(EmployeeCommission::VOID_REASON_ORDER_CANCELLED, $commission->void_reason);
        $this->assertSame(5, $stock->fresh()->quantity);
        $this->assertSame(2, InventoryMovement::where('reference_id', $orderId)->count());
        $voidedAt = $commission->voided_at?->getTimestamp();

        $this->postJson("/api/admin/orders/{$orderId}/status", ['status' => Order::STATUS_CANCELLED])->assertOk();
        $this->assertSame(5, $stock->fresh()->quantity);
        $this->assertSame(2, InventoryMovement::where('reference_id', $orderId)->count());
        $this->assertSame($voidedAt, $commission->fresh()->voided_at?->getTimestamp());

        $pendingOrder = $this->postJson('/api/admin/orders', [
            'branch_id' => $branch->id,
            'sales_employee_id' => $seller->id,
            'status' => Order::STATUS_PENDING,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated()->assertJsonPath('data.status', Order::STATUS_PENDING)->json('data.id');
        $this->assertSame(0, EmployeeCommission::where('order_id', $pendingOrder)->count());
        $this->postJson("/api/admin/orders/{$pendingOrder}/status", ['status' => Order::STATUS_CANCELLED])->assertOk();
        $this->assertSame(0, EmployeeCommission::where('order_id', $pendingOrder)->count());

        $secondOrder = $this->adminSale($branch, $seller, $product);
        $this->postJson("/api/admin/orders/{$secondOrder}/status", ['status' => Order::STATUS_COMPLETED])->assertOk();
        $this->postJson("/api/admin/orders/{$secondOrder}/status", ['status' => Order::STATUS_CANCELLED])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertSame(EmployeeCommission::STATUS_EARNED, EmployeeCommission::where('order_id', $secondOrder)->value('status'));
    }

    public function test_employee_branch_changes_and_catalog_deletion_do_not_rewrite_history(): void
    {
        $this->admin();
        $baq = $this->branch('BAQ');
        $bog = $this->branch('BOG');
        $seller = $this->employee($baq, 'Vendedora histórica');
        $product = $this->product(true, 50000, 'Producto histórico');
        $this->stock($baq, $product, 3);
        $orderId = $this->adminSale($baq, $seller, $product);

        $seller->update(['branch_id' => $bog->id, 'is_active' => false]);
        $this->getJson("/api/admin/commissions?branch_id={$baq->id}")
            ->assertOk()->assertJsonPath('data.commissions.total', 1)
            ->assertJsonPath('data.commissions.data.0.order.id', $orderId)
            ->assertJsonPath('data.commissions.data.0.branch.id', $baq->id);
        $this->getJson("/api/admin/commissions?branch_id={$bog->id}")
            ->assertOk()->assertJsonPath('data.commissions.total', 0);

        $this->deleteJson("/api/admin/products/{$product->id}")->assertStatus(409);
        $commission = EmployeeCommission::where('order_id', $orderId)->firstOrFail();
        $this->assertSame($product->id, $commission->product_id);
        $this->assertSame('Producto histórico', $commission->product_name_snapshot);
        $this->assertSame(50000, $commission->amount);
    }

    public function test_variant_physical_deletion_nulls_only_reference_and_preserves_snapshot(): void
    {
        $branch = $this->branch();
        $seller = $this->employee($branch);
        $product = $this->product(true, 50000, 'Pantalla padre');
        $variant = $this->variant($product, 'Variante histórica', 'VAR-HIST');
        $order = Order::create([
            'order_number' => 'HIST-'.uniqid(),
            'branch_id' => $branch->id,
            'sales_employee_id' => $seller->id,
            'origin' => Order::ORIGIN_CRM,
            'subtotal' => 100000,
            'discount_total' => 0,
            'total' => 100000,
            'status' => Order::STATUS_CONFIRMED,
            'payment_status' => Order::PAYMENT_UNPAID,
            'confirmed_at' => now(),
        ]);
        $order->items()->create([
            'item_type' => OrderItem::ITEM_TYPE_PRODUCT,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'product_name' => $product->name,
            'product_sku' => $product->sku,
            'variant_name' => $variant->name,
            'variant_sku' => $variant->sku,
            'unit_price' => 100000,
            'quantity' => 1,
            'subtotal' => 100000,
            'discount_amount' => 0,
            'total' => 100000,
        ]);
        app(CommissionService::class)->createPendingForOrder($order);

        $variant->delete();
        $commission = EmployeeCommission::sole();
        $this->assertNull($commission->product_variant_id);
        $this->assertSame('Variante histórica', $commission->variant_name_snapshot);
        $this->assertSame('VAR-HIST', $commission->sku_snapshot);
        $this->assertSame(50000, $commission->amount);
    }

    public function test_ledger_conflict_rolls_back_confirmation_inventory_and_status(): void
    {
        $admin = $this->admin();
        $branch = $this->branch();
        $seller = $this->employee($branch);
        $product = $this->product(true, 50000);
        $stock = $this->stock($branch, $product, 4);
        $orderId = $this->withHeader('Idempotency-Key', __METHOD__)->postJson('/api/orders', [
            'customer_name' => 'Cliente temporal',
            'customer_email' => 'qa@example.com',
            'customer_phone' => '3000000000',
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ])->assertCreated()->json('data.id');
        $order = Order::with('items')->findOrFail($orderId);
        $item = $order->items->sole();
        EmployeeCommission::create([
            'employee_id' => $seller->id,
            'branch_id' => $branch->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'product_id' => $product->id,
            'product_name_snapshot' => $item->product_name,
            'sku_snapshot' => $item->product_sku,
            'quantity' => 2,
            'unit_commission' => 50000,
            'amount' => 1,
            'status' => EmployeeCommission::STATUS_PENDING,
        ]);

        try {
            app(OrderService::class)->transition($order, Order::STATUS_CONFIRMED, $admin, assignment: [
                'branch_id' => $branch->id,
                'sales_employee_id' => $seller->id,
            ]);
            $this->fail('La confirmación debía fallar por snapshot conflictivo.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('conflicting immutable', $exception->getMessage());
        }

        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
        $this->assertNull($order->fresh()->stock_committed_at);
        $this->assertSame(4, $stock->fresh()->quantity);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertSame(1, EmployeeCommission::sole()->amount);
    }

    public function test_missing_linked_user_never_blocks_completion(): void
    {
        $this->admin();
        $branch = $this->branch();
        $withoutUser = $this->employee($branch, 'Sin acceso CRM');
        $product = $this->product(true, 50000);
        $this->stock($branch, $product, 5);
        $firstOrder = $this->adminSale($branch, $withoutUser, $product);
        $this->postJson("/api/admin/orders/{$firstOrder}/status", ['status' => Order::STATUS_COMPLETED])->assertOk();
        $this->assertSame(EmployeeCommission::STATUS_EARNED, EmployeeCommission::where('order_id', $firstOrder)->value('status'));
        $this->assertDatabaseCount('crm_notifications', 0);

        $inactiveUser = User::factory()->create(['role' => User::ROLE_USER, 'is_active' => false]);
        $inactiveRecipient = $this->employee($branch, 'Cuenta inactiva', $inactiveUser);
        $secondOrder = $this->adminSale($branch, $inactiveRecipient, $product);
        $this->postJson("/api/admin/orders/{$secondOrder}/status", ['status' => Order::STATUS_COMPLETED])->assertOk();
        $this->assertSame(EmployeeCommission::STATUS_EARNED, EmployeeCommission::where('order_id', $secondOrder)->value('status'));
        $this->assertDatabaseCount('crm_notifications', 0);
    }

    public function test_notification_failure_never_blocks_completion(): void
    {
        $this->admin();
        $branch = $this->branch();
        $product = $this->product(true, 50000);
        $this->stock($branch, $product, 5);

        $linked = User::factory()->create(['role' => User::ROLE_USER, 'is_active' => true]);
        $seller = $this->employee($branch, 'Con fallo aislado', $linked);
        $notifications = Mockery::mock(CrmNotificationService::class);
        $notifications->shouldReceive('createFor')->once()->andThrow(new RuntimeException('notification unavailable'));
        $this->app->instance(CrmNotificationService::class, $notifications);
        $secondOrder = $this->adminSale($branch, $seller, $product);

        $this->postJson("/api/admin/orders/{$secondOrder}/status", ['status' => Order::STATUS_COMPLETED])
            ->assertOk()->assertJsonPath('data.status', Order::STATUS_COMPLETED);
        $this->assertSame(EmployeeCommission::STATUS_EARNED, EmployeeCommission::where('order_id', $secondOrder)->value('status'));
        $this->assertNotNull(Order::findOrFail($secondOrder)->completed_at);
        $this->assertDatabaseCount('crm_notifications', 0);
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function branch(string $code = 'BAQ'): Branch
    {
        return $this->branches[$code] ??= Branch::create([
            'code' => $code,
            'slug' => strtolower($code).'-'.uniqid(),
            'name' => "Sede {$code}",
            'city' => $code === 'BOG' ? 'Bogotá' : 'Barranquilla',
            'is_active' => true,
        ]);
    }

    private function employee(Branch $branch, string $name = 'Asesor', ?User $user = null): Employee
    {
        return Employee::create([
            'user_id' => $user?->id,
            'branch_id' => $branch->id,
            'name' => $name.' '.uniqid(),
            'job_title' => 'Asesor',
            'is_active' => true,
        ]);
    }

    private function product(bool $enabled, ?int $amount, string $name = 'Producto comisionable'): Product
    {
        return Product::create([
            'name' => $name,
            'normalized_name' => strtolower($name),
            'slug' => 'producto-'.uniqid(),
            'sku' => 'SKU-'.uniqid(),
            'price' => 100000,
            'cost_price' => 40000,
            'is_active' => true,
            'is_visible' => true,
            'commission_enabled' => $enabled,
            'commission_amount' => $amount,
        ]);
    }

    private function variant(Product $product, string $name, string $sku): ProductVariant
    {
        return ProductVariant::create([
            'product_id' => $product->id,
            'name' => $name,
            'normalized_name' => strtolower($name),
            'sku' => $sku,
            'price' => 100000,
            'cost_price' => 40000,
            'is_default' => true,
            'is_active' => true,
            'is_visible' => true,
        ]);
    }

    private function stock(Branch $branch, Product $product, int $quantity, ?ProductVariant $variant = null): InventoryStock
    {
        $inventoryItem = InventoryItem::firstOrCreate($variant
            ? ['product_id' => null, 'product_variant_id' => $variant->id]
            : ['product_id' => $product->id, 'product_variant_id' => null]);

        return InventoryStock::create([
            'branch_id' => $branch->id,
            'inventory_item_id' => $inventoryItem->id,
            'quantity' => $quantity,
            'minimum_quantity' => 0,
        ]);
    }

    private function service(): Service
    {
        $category = ServiceCategory::create([
            'name' => 'Instalación '.uniqid(),
            'slug' => 'instalacion-'.uniqid(),
            'is_active' => true,
        ]);

        return Service::create([
            'service_category_id' => $category->id,
            'name' => 'Instalación profesional',
            'slug' => 'instalacion-profesional-'.uniqid(),
            'price' => 80000,
            'cost' => 30000,
            'estimated_duration_minutes' => 60,
            'is_active' => true,
        ]);
    }

    private function adminSale(Branch $branch, Employee $seller, Product $product, int $quantity = 1): int
    {
        return (int) $this->postJson('/api/admin/orders', [
            'branch_id' => $branch->id,
            'sales_employee_id' => $seller->id,
            'items' => [['product_id' => $product->id, 'quantity' => $quantity]],
        ])->assertCreated()->json('data.id');
    }
}
