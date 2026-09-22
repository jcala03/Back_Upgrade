<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeCommission;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\UserCapability;
use App\Services\CommissionService;
use App\Support\Business\BusinessContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class CommissionLedgerApiPhaseFourTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-24 12:00:00', BusinessContext::TIMEZONE));
    }

    public function createApplication()
    {
        $connection = trim((string) getenv('TEST_DB_CONNECTION'));
        $database = trim((string) getenv('TEST_DB_DATABASE'));

        if ($connection !== 'mysql' || $database === '') {
            throw new RuntimeException('CommissionLedgerApiPhaseFourTest requiere TEST_DB_CONNECTION=mysql y TEST_DB_DATABASE explícita.');
        }
        if (strcasecmp($database, 'upgrade') === 0) {
            throw new RuntimeException('CommissionLedgerApiPhaseFourTest no puede ejecutarse contra la base principal upgrade.');
        }

        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $app['config']->set('database.default', $connection);
        $app['config']->set("database.connections.{$connection}.database", $database);

        if (strcasecmp((string) $app['config']->get("database.connections.{$connection}.database"), 'upgrade') === 0) {
            throw new RuntimeException('La base configurada para CommissionLedgerApiPhaseFourTest no puede ser upgrade.');
        }

        return $app;
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_service_freezes_a_pending_snapshot_and_is_idempotent_without_recalculation(): void
    {
        $branch = $this->branch();
        $employee = $this->employee($branch);
        $product = $this->product(['commission_enabled' => true, 'commission_amount' => 50000]);
        [$order, $item] = $this->orderItem($employee, $branch, $product, 2);
        $service = app(CommissionService::class);

        $first = $service->createPendingForOrder($order);
        $second = $service->createPendingForOrder($order);

        $this->assertCount(1, $first);
        $this->assertCount(1, $second);
        $this->assertDatabaseCount('employee_commissions', 1);
        $this->assertDatabaseHas('employee_commissions', [
            'employee_id' => $employee->id,
            'branch_id' => $branch->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'unit_commission' => 50000,
            'amount' => 100000,
            'status' => EmployeeCommission::STATUS_PENDING,
        ]);

        $product->update(['commission_amount' => 60000, 'name' => 'Nombre nuevo']);
        $commission = EmployeeCommission::firstOrFail();
        $this->assertSame(50000, $commission->unit_commission);
        $this->assertSame(100000, $commission->amount);
        $this->assertSame($item->product_name, $commission->product_name_snapshot);
        $this->assertSame([
            EmployeeCommission::STATUS_PENDING,
            EmployeeCommission::STATUS_EARNED,
            EmployeeCommission::STATUS_VOIDED,
        ], EmployeeCommission::statuses());
    }

    public function test_service_rejects_a_conflicting_existing_snapshot_instead_of_overwriting_it(): void
    {
        $branch = $this->branch();
        $employee = $this->employee($branch);
        $product = $this->product(['commission_enabled' => true, 'commission_amount' => 50000]);
        [$order] = $this->orderItem($employee, $branch, $product, 1);
        $service = app(CommissionService::class);
        $service->createPendingForOrder($order);
        EmployeeCommission::query()->update(['amount' => 1]);

        $this->expectException(LogicException::class);
        $service->createPendingForOrder($order);
    }

    public function test_unique_constraint_prevents_a_duplicate_item_employee_commission(): void
    {
        $branch = $this->branch();
        $employee = $this->employee($branch);
        $product = $this->product(['commission_enabled' => true, 'commission_amount' => 50000]);
        [$order] = $this->orderItem($employee, $branch, $product);
        app(CommissionService::class)->createPendingForOrder($order);
        $duplicate = EmployeeCommission::sole()->replicate();

        $this->expectException(QueryException::class);
        $duplicate->save();
    }

    public function test_product_deletion_nulls_references_and_preserves_financial_snapshots(): void
    {
        $branch = $this->branch();
        $employee = $this->employee($branch);
        $product = $this->product(['commission_enabled' => true, 'commission_amount' => 50000]);
        [$order] = $this->orderItem($employee, $branch, $product, 1);
        app(CommissionService::class)->createPendingForOrder($order);

        $product->delete();

        $commission = EmployeeCommission::firstOrFail();
        $this->assertNull($commission->product_id);
        $this->assertSame('Producto histórico', $commission->product_name_snapshot);
        $this->assertSame(50000, $commission->amount);
        $this->assertDatabaseCount('employee_commissions', 1);
    }

    public function test_admin_commission_api_is_read_only_permissioned_filtered_and_paginated(): void
    {
        $branch = $this->branch();
        $otherBranch = $this->branch('BOG');
        $employee = $this->employee($branch, 'María Comercial');
        $otherEmployee = $this->employee($otherBranch, 'Otro Vendedor');
        $first = $this->commission($employee, $branch, [
            'status' => EmployeeCommission::STATUS_EARNED,
            'product_name_snapshot' => 'Pantalla BMW',
            'sku_snapshot' => 'BMW-123',
        ]);
        $this->commission($otherEmployee, $otherBranch, [
            'status' => EmployeeCommission::STATUS_PENDING,
            'product_name_snapshot' => 'Cámara',
        ]);

        $this->getJson('/api/admin/commissions')->assertUnauthorized();

        $user = User::factory()->create(['role' => User::ROLE_USER]);
        Sanctum::actingAs($user);
        $this->getJson('/api/admin/commissions')->assertForbidden();
        $this->getJson("/api/admin/commissions/{$first->id}")->assertForbidden();

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->assertTrue($admin->hasPermission('commissions.view'));
        Sanctum::actingAs($admin);
        $this->getJson('/api/admin/commissions?employee_id='.$employee->id
            .'&branch_id='.$branch->id
            .'&status=earned&product_id='.$first->product_id
            .'&search=BMW&from='.now()->toDateString().'&to='.now()->toDateString().'&per_page=1')
            ->assertOk()
            ->assertJsonPath('data.date_basis', 'created_at')
            ->assertJsonPath('data.commissions.total', 1)
            ->assertJsonCount(1, 'data.commissions.data')
            ->assertJsonPath('data.commissions.data.0.id', $first->id)
            ->assertJsonPath('data.commissions.data.0.employee.name', 'María Comercial')
            ->assertJsonPath('data.commissions.data.0.branch.code', 'BAQ')
            ->assertJsonPath('data.commissions.data.0.order.order_number', $first->order->order_number)
            ->assertJsonPath('data.commissions.data.0.product.name', 'Pantalla BMW')
            ->assertJsonMissingPath('data.commissions.data.0.employee.user')
            ->assertJsonMissingPath('data.commissions.data.0.customer');
        $this->getJson("/api/admin/commissions/{$first->id}")
            ->assertOk()
            ->assertJsonPath('data.sku_snapshot', 'BMW-123')
            ->assertJsonPath('data.amount', 50000);
        $this->getJson('/api/admin/commissions?per_page=101')
            ->assertUnprocessable()->assertJsonValidationErrors('per_page');

        $this->postJson('/api/admin/commissions', [])->assertMethodNotAllowed();
        $this->patchJson("/api/admin/commissions/{$first->id}", ['amount' => 1])->assertMethodNotAllowed();
        $this->deleteJson("/api/admin/commissions/{$first->id}")->assertMethodNotAllowed();
    }

    public function test_my_commissions_requires_capability_and_never_discloses_another_seller(): void
    {
        $branch = $this->branch();
        $user = User::factory()->create(['role' => User::ROLE_USER, 'is_active' => true]);
        Sanctum::actingAs($user);
        $this->getJson('/api/my/commissions')->assertForbidden();
        $this->getJson('/api/my/commissions/summary')->assertForbidden();

        UserCapability::create([
            'user_id' => $user->id,
            'capability' => UserCapability::COMMISSIONS_VIEW_OWN,
        ]);
        $user->unsetRelation('capabilities');
        $this->getJson('/api/my/commissions')->assertOk()
            ->assertJsonPath('data.employee_linked', false)
            ->assertJsonPath('data.commissions', []);
        $this->getJson('/api/my/commissions/summary')->assertOk()
            ->assertJsonPath('data.employee_linked', false)
            ->assertJsonPath('data.current_month.earned_amount', 0);

        $employee = $this->employee($branch, 'Propietaria', $user);
        $otherEmployee = $this->employee($branch, 'Misma sede, otra persona');
        $own = $this->commission($employee, $branch, [
            'status' => EmployeeCommission::STATUS_PENDING,
            'product_name_snapshot' => 'Pantalla propia',
        ]);
        $this->commission($otherEmployee, $branch, [
            'status' => EmployeeCommission::STATUS_PENDING,
            'product_name_snapshot' => 'Producto ajeno',
        ]);
        $user->unsetRelation('employee');

        $this->getJson('/api/my/commissions?status=pending&from='.now()->toDateString().'&to='.now()->toDateString())
            ->assertOk()
            ->assertJsonPath('data.employee_linked', true)
            ->assertJsonPath('data.commissions.total', 1)
            ->assertJsonPath('data.commissions.data.0.id', $own->id)
            ->assertJsonPath('data.commissions.data.0.product.name', 'Pantalla propia')
            ->assertJsonPath('data.commissions.data.0.branch.code', 'BAQ')
            ->assertJsonMissingPath('data.commissions.data.0.employee')
            ->assertJsonMissingPath('data.commissions.data.0.unit_cost')
            ->assertJsonMissingPath('data.commissions.data.0.margin')
            ->assertJsonMissingPath('data.commissions.data.0.customer');
        $this->getJson('/api/my/commissions?employee_id='.$otherEmployee->id)
            ->assertUnprocessable()->assertJsonValidationErrors('employee_id');
        $this->getJson('/api/my/commissions?branch_id='.$branch->id)
            ->assertUnprocessable()->assertJsonValidationErrors('branch_id');
        $this->getJson('/api/my/commissions?status=invalid')
            ->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_my_current_month_summary_uses_status_specific_bogota_event_boundaries(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-24 12:00:00', BusinessContext::TIMEZONE));
        $branch = $this->branch();
        $user = User::factory()->create(['role' => User::ROLE_USER, 'is_active' => true]);
        UserCapability::create([
            'user_id' => $user->id,
            'capability' => UserCapability::COMMISSIONS_VIEW_OWN,
        ]);
        $employee = $this->employee($branch, 'Resumen', $user);
        $this->commission($employee, $branch, [
            'status' => EmployeeCommission::STATUS_PENDING,
            'quantity' => 2,
            'amount' => 100000,
            'created_at' => '2026-08-01 05:00:00',
        ]);
        $this->commission($employee, $branch, [
            'status' => EmployeeCommission::STATUS_EARNED,
            'quantity' => 1,
            'amount' => 60000,
            'created_at' => '2026-07-10 12:00:00',
            'earned_at' => '2026-08-05 15:00:00',
        ]);
        $this->commission($employee, $branch, [
            'status' => EmployeeCommission::STATUS_VOIDED,
            'quantity' => 3,
            'amount' => 150000,
            'created_at' => '2026-07-15 12:00:00',
            'voided_at' => '2026-08-10 15:00:00',
        ]);
        $this->commission($employee, $branch, [
            'status' => EmployeeCommission::STATUS_EARNED,
            'amount' => 999999,
            'earned_at' => '2026-08-01 04:59:59',
        ]);
        Sanctum::actingAs($user);

        $this->getJson('/api/my/commissions/summary')
            ->assertOk()
            ->assertJsonPath('data.employee_linked', true)
            ->assertJsonPath('data.period.from', '2026-08-01')
            ->assertJsonPath('data.period.to', '2026-08-31')
            ->assertJsonPath('data.period.timezone', 'America/Bogota')
            ->assertJsonPath('data.current_month.pending_amount', 100000)
            ->assertJsonPath('data.current_month.earned_amount', 60000)
            ->assertJsonPath('data.current_month.voided_amount', 150000)
            ->assertJsonPath('data.current_month.total_rows', 3)
            ->assertJsonPath('data.current_month.total_units', 6)
            ->assertJsonPath('data.date_basis.earned', 'earned_at');
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

    private function employee(Branch $branch, string $name = 'Asesor', ?User $user = null): Employee
    {
        return Employee::create([
            'user_id' => $user?->id,
            'branch_id' => $branch->id,
            'name' => $name,
            'job_title' => 'Asesor',
            'is_active' => true,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function product(array $overrides = []): Product
    {
        return Product::create([
            'name' => 'Producto histórico',
            'slug' => 'producto-'.uniqid(),
            'sku' => 'SKU-'.uniqid(),
            'price' => 100000,
            'cost_price' => 40000,
            'is_active' => true,
            'is_visible' => true,
            'commission_enabled' => false,
            'commission_amount' => null,
            ...$overrides,
        ]);
    }

    /** @return array{0: Order, 1: OrderItem} */
    private function orderItem(Employee $employee, Branch $branch, Product $product, int $quantity = 1): array
    {
        $order = Order::create([
            'order_number' => 'COM-'.uniqid(),
            'branch_id' => $branch->id,
            'sales_employee_id' => $employee->id,
            'origin' => Order::ORIGIN_CRM,
            'subtotal' => 100000 * $quantity,
            'discount_total' => 0,
            'total' => 100000 * $quantity,
            'status' => Order::STATUS_CONFIRMED,
            'payment_status' => Order::PAYMENT_UNPAID,
            'confirmed_at' => now(),
        ]);
        $item = $order->items()->create([
            'item_type' => OrderItem::ITEM_TYPE_PRODUCT,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_slug' => $product->slug,
            'product_sku' => $product->sku,
            'unit_price' => 100000,
            'quantity' => $quantity,
            'subtotal' => 100000 * $quantity,
            'discount_amount' => 0,
            'total' => 100000 * $quantity,
        ]);

        return [$order, $item];
    }

    /** @param array<string, mixed> $overrides */
    private function commission(Employee $employee, Branch $branch, array $overrides = []): EmployeeCommission
    {
        $product = $this->product();
        [$order, $item] = $this->orderItem($employee, $branch, $product);
        $status = $overrides['status'] ?? EmployeeCommission::STATUS_PENDING;
        $commission = EmployeeCommission::create([
            'employee_id' => $employee->id,
            'branch_id' => $branch->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'product_id' => $product->id,
            'product_name_snapshot' => $overrides['product_name_snapshot'] ?? $product->name,
            'sku_snapshot' => $overrides['sku_snapshot'] ?? $product->sku,
            'quantity' => $overrides['quantity'] ?? 1,
            'unit_commission' => $overrides['unit_commission'] ?? 50000,
            'amount' => $overrides['amount'] ?? 50000,
            'status' => $status,
            'earned_at' => $overrides['earned_at'] ?? ($status === EmployeeCommission::STATUS_EARNED ? now() : null),
            'voided_at' => $overrides['voided_at'] ?? ($status === EmployeeCommission::STATUS_VOIDED ? now() : null),
            'void_reason' => $status === EmployeeCommission::STATUS_VOIDED
                ? EmployeeCommission::VOID_REASON_ORDER_CANCELLED
                : null,
        ]);

        foreach (['created_at', 'updated_at', 'earned_at', 'voided_at'] as $field) {
            if (array_key_exists($field, $overrides)) {
                $commission->setAttribute($field, $overrides[$field]);
            }
        }
        if (array_intersect(['created_at', 'updated_at', 'earned_at', 'voided_at'], array_keys($overrides))) {
            $commission->timestamps = false;
            $commission->save();
            $commission->timestamps = true;
        }

        return $commission->fresh();
    }
}
