<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\EmployeeCommission;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\User;
use App\Models\UserCapability;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class BusinessOverviewPhaseSixTest extends TestCase
{
    use RefreshDatabase;

    public function createApplication()
    {
        $connection = trim((string) getenv('TEST_DB_CONNECTION'));
        $database = trim((string) getenv('TEST_DB_DATABASE'));

        if ($connection !== 'mysql' || $database === '' || strcasecmp($database, 'upgrade') === 0) {
            throw new RuntimeException('BusinessOverviewPhaseSixTest requiere una base MySQL temporal distinta de upgrade.');
        }

        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $app['config']->set('database.default', $connection);
        $app['config']->set("database.connections.{$connection}.database", $database);

        return $app;
    }

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

    public function test_permission_is_base_for_user_and_not_an_individual_capability(): void
    {
        $this->getJson('/api/my/business-overview')->assertUnauthorized();

        $user = User::factory()->create(['role' => User::ROLE_USER, 'is_active' => true]);
        $this->assertTrue($user->hasPermission('business_overview.view'));
        $this->assertTrue($user->hasPermission('notifications.view'));
        $this->assertTrue($user->hasPermission('notifications.update'));
        $this->assertFalse($user->hasPermission(UserCapability::ORDERS_VIEW_OWN));
        $this->assertFalse($user->hasPermission(UserCapability::QUOTATIONS_VIEW_OWN));
        $this->assertFalse($user->hasPermission(UserCapability::COMMISSIONS_VIEW_OWN));
        $this->assertNotContains('business_overview.view', UserCapability::allowed());
        $this->assertNotContains('sales', User::roles());

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
        $this->assertTrue($admin->hasPermission('business_overview.view'));
        $this->assertTrue($admin->hasPermission('dashboard.view'));
        $this->assertTrue($admin->hasPermission('reports.financials'));

        Sanctum::actingAs($admin);
        $this->putJson("/api/admin/users/{$user->id}/capabilities", [
            'capabilities' => ['business_overview.view'],
        ])->assertUnprocessable()->assertJsonValidationErrors('capabilities.0');

        $inactive = User::factory()->create(['role' => User::ROLE_USER, 'is_active' => false]);
        Sanctum::actingAs($inactive);
        $this->getJson('/api/my/business-overview')->assertForbidden();
    }

    public function test_employee_context_is_safe_and_inactive_branch_remains_readable(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);

        $this->getJson('/api/my/business-overview')->assertOk()
            ->assertJsonPath('data.employee_linked', false)
            ->assertJsonPath('data.employee', null)
            ->assertJsonPath('data.branch', null)
            ->assertJsonPath('data.company', null)
            ->assertJsonPath('data.my_branch', null)
            ->assertJsonPath('data.personal.sales.available', false);

        $branch = $this->branch('BAQ');
        $employee = $this->employee($branch, $user);
        $employee->update(['is_active' => false]);
        $this->getJson('/api/my/business-overview')->assertOk()
            ->assertJsonPath('data.employee_linked', false)
            ->assertJsonPath('data.company', null);

        $employee->update(['is_active' => true, 'branch_id' => null]);
        $this->getJson('/api/my/business-overview')->assertOk()
            ->assertJsonPath('data.employee_linked', false)
            ->assertJsonPath('data.my_branch', null);

        $employee->update(['branch_id' => $branch->id]);
        $branch->update(['is_active' => false]);
        $this->getJson('/api/my/business-overview')->assertOk()
            ->assertJsonPath('data.employee_linked', true)
            ->assertJsonPath('data.employee.id', $employee->id)
            ->assertJsonPath('data.branch.code', 'BAQ')
            ->assertJsonPath('data.branch.is_active', false)
            ->assertJsonPath('data.company.sales_count', 0);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
        Sanctum::actingAs($admin);
        $this->getJson('/api/my/business-overview')->assertOk()
            ->assertJsonPath('data.employee_linked', false)
            ->assertJsonPath('data.company', null);
    }

    public function test_request_rejects_context_and_sensitive_parameter_spoofing(): void
    {
        $user = $this->user();
        $branch = $this->branch('BAQ');
        $this->employee($branch, $user);
        Sanctum::actingAs($user);

        foreach (['branch_id', 'employee_id', 'sales_employee_id', 'user_id', 'financials', 'include_costs', 'include_other_branches', 'revenue'] as $parameter) {
            $this->getJson('/api/my/business-overview?'.$parameter.'=1')
                ->assertUnprocessable()
                ->assertJsonValidationErrors($parameter);
        }
    }

    public function test_company_branch_and_personal_metrics_are_scoped_and_privacy_safe(): void
    {
        [$baq, $bog] = [$this->branch('BAQ'), $this->branch('BOG')];
        $owner = $this->user('Colaborador BAQ');
        $ownerEmployee = $this->employee($baq, $owner, 'Colaborador BAQ');
        $otherEmployee = $this->employee($baq, null, 'Nombre vendedor confidencial');
        $bogUser = $this->user('Colaborador BOG');
        $bogEmployee = $this->employee($bog, $bogUser, 'Colaborador BOG');
        $this->grant($owner, [
            UserCapability::ORDERS_VIEW_OWN,
            UserCapability::QUOTATIONS_VIEW_OWN,
        ]);

        $sharedCustomer = Customer::create([
            'name' => 'Cliente secreto',
            'email' => 'privado@example.com',
            'phone' => '3001234567',
            'document' => '11223344',
            'is_active' => true,
        ]);
        $otherCustomer = Customer::create(['name' => 'Otro cliente', 'is_active' => true]);
        $screen = $this->product('Pantalla', 'PAN-1');
        $camera = $this->product('Cámara', 'CAM-1');

        $ownOrder = $this->order($baq, $ownerEmployee, $sharedCustomer, Order::STATUS_CONFIRMED, 100000);
        $ownOrder->update(['vehicle_vin' => 'VIN-PRIVADO-123']);
        $this->productItem($ownOrder, $screen, 2, 50000, 10000);
        $this->serviceItem($ownOrder, 8);
        $baqOther = $this->order($baq, $otherEmployee, $otherCustomer, Order::STATUS_COMPLETED, 200000);
        $this->productItem($baqOther, $camera, 4, 50000, 20000);
        $bogOrder = $this->order($bog, $bogEmployee, $sharedCustomer, Order::STATUS_CONFIRMED, 300000);
        $this->productItem($bogOrder, $screen, 3, 100000, 30000);
        $pending = $this->order($baq, $ownerEmployee, $sharedCustomer, Order::STATUS_PENDING, 900000);
        $this->productItem($pending, $screen, 9, 100000, 1);
        $cancelled = $this->order($bog, $bogEmployee, $sharedCustomer, Order::STATUS_CANCELLED, 900000);
        $this->productItem($cancelled, $screen, 9, 100000, 1);

        $this->quotation($baq, $ownerEmployee, Quotation::STATUS_CONVERTED, '2026-08-30');
        $this->quotation($baq, $otherEmployee, Quotation::STATUS_REJECTED, '2026-08-30');
        $this->quotation($bog, $bogEmployee, Quotation::STATUS_CONVERTED, '2026-08-30');
        $this->quotation($bog, $bogEmployee, Quotation::STATUS_DRAFT, '2026-08-20');
        Payment::create([
            'order_id' => $ownOrder->id,
            'amount' => 50000,
            'method' => Payment::METHOD_CASH,
            'status' => Payment::STATUS_COMPLETED,
            'paid_at' => '2026-08-24 13:00:00',
        ]);

        Sanctum::actingAs($owner);
        $response = $this->getJson('/api/my/business-overview?from=2026-08-01&to=2026-08-31')->assertOk();
        $response->assertJsonPath('data.employee_linked', true)
            ->assertJsonPath('data.branch.code', 'BAQ')
            ->assertJsonPath('data.company.sales_count', 3)
            ->assertJsonPath('data.company.product_units', 9)
            ->assertJsonPath('data.company.quotations_count', 4)
            ->assertJsonPath('data.company.quotation_conversion_rate', 50)
            ->assertJsonPath('data.company.customers_served', 2)
            ->assertJsonCount(2, 'data.company.top_products')
            ->assertJsonPath('data.company.top_products.0.product_name', 'Pantalla')
            ->assertJsonPath('data.company.top_products.0.units', 5)
            ->assertJsonPath('data.company.top_products.1.product_name', 'Cámara')
            ->assertJsonPath('data.company.top_products.1.units', 4)
            ->assertJsonPath('data.my_branch.sales_count', 2)
            ->assertJsonPath('data.my_branch.product_units', 6)
            ->assertJsonPath('data.my_branch.quotations_count', 2)
            ->assertJsonPath('data.my_branch.quotation_conversion_rate', 50)
            ->assertJsonPath('data.my_branch.customers_served', 2)
            ->assertJsonPath('data.personal.sales.available', true)
            ->assertJsonPath('data.personal.sales.sales_count', 1)
            ->assertJsonPath('data.personal.sales.product_units', 2)
            ->assertJsonPath('data.personal.sales.sales_total', 100000)
            ->assertJsonPath('data.personal.quotations.available', true)
            ->assertJsonPath('data.personal.quotations.quotation_count', 1)
            ->assertJsonPath('data.personal.quotations.converted_count', 1)
            ->assertJsonPath('data.personal.quotations.conversion_rate', 100)
            ->assertJsonPath('data.personal.commissions.available', false)
            ->assertJsonMissingPath('data.company.sales_total')
            ->assertJsonMissingPath('data.company.revenue')
            ->assertJsonMissingPath('data.company.payments')
            ->assertJsonMissingPath('data.company.receivables')
            ->assertJsonMissingPath('data.company.cost')
            ->assertJsonMissingPath('data.company.margin')
            ->assertJsonMissingPath('data.company.profit')
            ->assertJsonMissingPath('data.branches')
            ->assertJsonMissingPath('data.personal.sales.unit_cost');

        $json = $response->getContent();
        $this->assertStringNotContainsString('privado@example.com', $json);
        $this->assertStringNotContainsString('3001234567', $json);
        $this->assertStringNotContainsString('11223344', $json);
        $this->assertStringNotContainsString('Cliente secreto', $json);
        $this->assertStringNotContainsString('Nombre vendedor confidencial', $json);
        $this->assertStringNotContainsString('VIN-PRIVADO-123', $json);
        $this->assertStringNotContainsString('vehicle_vin', $json);
        $this->assertStringNotContainsString('unit_cost', $json);
        $this->assertStringNotContainsString('salary', $json);
        $this->assertStringNotContainsString('payroll', $json);

        Sanctum::actingAs($bogUser);
        $this->getJson('/api/my/business-overview?from=2026-08-01&to=2026-08-31')->assertOk()
            ->assertJsonPath('data.branch.code', 'BOG')
            ->assertJsonPath('data.company.sales_count', 3)
            ->assertJsonPath('data.my_branch.sales_count', 1)
            ->assertJsonPath('data.my_branch.product_units', 3)
            ->assertJsonPath('data.my_branch.quotations_count', 2)
            ->assertJsonMissingPath('data.branches');
    }

    public function test_personal_metrics_are_unavailable_without_commercial_capabilities(): void
    {
        $branch = $this->branch('BAQ');
        $user = $this->user();
        $employee = $this->employee($branch, $user);
        $customer = Customer::create(['name' => 'Cliente', 'is_active' => true]);
        $order = $this->order($branch, $employee, $customer, Order::STATUS_CONFIRMED, 150000);
        $this->productItem($order, $this->product(), 2, 75000, 25000);
        $this->quotation($branch, $employee, Quotation::STATUS_CONVERTED, '2026-08-30');

        Sanctum::actingAs($user);
        $this->getJson('/api/my/business-overview')->assertOk()
            ->assertJsonPath('data.company.sales_count', 1)
            ->assertJsonPath('data.personal.sales.available', false)
            ->assertJsonPath('data.personal.quotations.available', false)
            ->assertJsonPath('data.personal.commissions.available', false)
            ->assertJsonMissingPath('data.personal.sales.sales_count')
            ->assertJsonMissingPath('data.personal.quotations.quotation_count')
            ->assertJsonMissingPath('data.personal.commissions.pending_amount');
    }

    public function test_personal_metrics_keep_employee_history_across_branch_changes(): void
    {
        [$baq, $bog] = [$this->branch('BAQ'), $this->branch('BOG')];
        $user = $this->user();
        $employee = $this->employee($baq, $user);
        $this->grant($user, [UserCapability::ORDERS_VIEW_OWN, UserCapability::QUOTATIONS_VIEW_OWN]);
        $customer = Customer::create(['name' => 'Cliente', 'is_active' => true]);
        $oldOrder = $this->order($baq, $employee, $customer, Order::STATUS_CONFIRMED, 100000);
        $this->productItem($oldOrder, $this->product('Anterior', 'OLD'), 1, 100000, 30000);
        $this->quotation($baq, $employee, Quotation::STATUS_CONVERTED, '2026-08-30');
        $employee->update(['branch_id' => $bog->id]);

        Sanctum::actingAs($user);
        $this->getJson('/api/my/business-overview')->assertOk()
            ->assertJsonPath('data.branch.code', 'BOG')
            ->assertJsonPath('data.my_branch.sales_count', 0)
            ->assertJsonPath('data.my_branch.quotations_count', 0)
            ->assertJsonPath('data.personal.sales.sales_count', 1)
            ->assertJsonPath('data.personal.sales.sales_total', 100000)
            ->assertJsonPath('data.personal.quotations.quotation_count', 1);
    }

    public function test_personal_commissions_use_status_event_dates_and_never_other_employee(): void
    {
        $branch = $this->branch('BAQ');
        $user = $this->user();
        $employee = $this->employee($branch, $user);
        $other = $this->employee($branch, null, 'Otro comisionista secreto');
        $this->grant($user, [UserCapability::COMMISSIONS_VIEW_OWN]);

        $this->commission($employee, $branch, EmployeeCommission::STATUS_PENDING, 10000, 1, '2026-08-02 12:00:00');
        $this->commission($employee, $branch, EmployeeCommission::STATUS_EARNED, 20000, 2, '2026-08-03 12:00:00');
        $this->commission($employee, $branch, EmployeeCommission::STATUS_VOIDED, 30000, 3, '2026-08-04 12:00:00');
        $this->commission($other, $branch, EmployeeCommission::STATUS_EARNED, 999999, 9, '2026-08-05 12:00:00');

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/my/business-overview?from=2026-08-01&to=2026-08-31')->assertOk();
        $response->assertJsonPath('data.personal.commissions.available', true)
            ->assertJsonPath('data.personal.commissions.pending_amount', 10000)
            ->assertJsonPath('data.personal.commissions.earned_amount', 20000)
            ->assertJsonPath('data.personal.commissions.voided_amount', 30000)
            ->assertJsonPath('data.personal.commissions.earned_units', 2)
            ->assertJsonPath('data.personal.sales.available', false)
            ->assertJsonMissingPath('data.personal.commissions.salary')
            ->assertJsonMissingPath('data.personal.commissions.payroll');
        $this->assertStringNotContainsString('999999', $response->getContent());
        $this->assertStringNotContainsString('Otro comisionista secreto', $response->getContent());
    }

    public function test_period_defaults_validates_and_uses_bogota_utc_boundaries(): void
    {
        $branch = $this->branch('BAQ');
        $user = $this->user();
        $employee = $this->employee($branch, $user);
        $customer = Customer::create(['name' => 'Cliente', 'is_active' => true]);
        $this->order($branch, $employee, $customer, Order::STATUS_CONFIRMED, 1, '2026-08-01 04:59:59');
        $this->order($branch, $employee, $customer, Order::STATUS_CONFIRMED, 1, '2026-08-01 05:00:00');
        $this->order($branch, $employee, $customer, Order::STATUS_COMPLETED, 1, '2026-09-01 04:59:59');
        $this->order($branch, $employee, $customer, Order::STATUS_CONFIRMED, 1, '2026-09-01 05:00:00');
        Sanctum::actingAs($user);

        $this->getJson('/api/my/business-overview')->assertOk()
            ->assertJsonPath('data.period.from', '2026-08-01')
            ->assertJsonPath('data.period.to', '2026-08-31')
            ->assertJsonPath('data.period.timezone', 'America/Bogota')
            ->assertJsonPath('data.company.sales_count', 2);
        $this->getJson('/api/my/business-overview?from=2026-08-01&to=2026-08-01')->assertOk()
            ->assertJsonPath('data.company.sales_count', 1);
        $this->getJson('/api/my/business-overview?from=2026-08-02&to=2026-08-01')
            ->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->getJson('/api/my/business-overview?from=2025-01-01&to=2026-01-02')
            ->assertUnprocessable()->assertJsonValidationErrors('to');
    }

    public function test_valid_employee_with_no_operations_gets_stable_zero_metrics(): void
    {
        $branch = $this->branch('BAQ');
        $user = $this->user();
        $this->employee($branch, $user);
        $this->grant($user, [
            UserCapability::ORDERS_VIEW_OWN,
            UserCapability::QUOTATIONS_VIEW_OWN,
            UserCapability::COMMISSIONS_VIEW_OWN,
        ]);
        Sanctum::actingAs($user);

        $this->getJson('/api/my/business-overview')->assertOk()
            ->assertJsonPath('data.company.sales_count', 0)
            ->assertJsonPath('data.company.product_units', 0)
            ->assertJsonPath('data.company.quotations_count', 0)
            ->assertJsonPath('data.company.quotation_conversion_rate', 0)
            ->assertJsonPath('data.company.customers_served', 0)
            ->assertJsonPath('data.company.top_products', [])
            ->assertJsonPath('data.my_branch.sales_count', 0)
            ->assertJsonPath('data.my_branch.product_units', 0)
            ->assertJsonPath('data.my_branch.quotations_count', 0)
            ->assertJsonPath('data.my_branch.quotation_conversion_rate', 0)
            ->assertJsonPath('data.my_branch.customers_served', 0)
            ->assertJsonPath('data.my_branch.top_products', [])
            ->assertJsonPath('data.personal.sales.sales_count', 0)
            ->assertJsonPath('data.personal.sales.product_units', 0)
            ->assertJsonPath('data.personal.sales.sales_total', 0)
            ->assertJsonPath('data.personal.quotations.quotation_count', 0)
            ->assertJsonPath('data.personal.quotations.converted_count', 0)
            ->assertJsonPath('data.personal.quotations.conversion_rate', 0)
            ->assertJsonPath('data.personal.commissions.pending_amount', 0)
            ->assertJsonPath('data.personal.commissions.earned_amount', 0)
            ->assertJsonPath('data.personal.commissions.voided_amount', 0)
            ->assertJsonPath('data.personal.commissions.earned_units', 0);
    }

    public function test_top_products_is_limited_to_five_rows(): void
    {
        $branch = $this->branch('BAQ');
        $user = $this->user();
        $employee = $this->employee($branch, $user);
        $customer = Customer::create(['name' => 'Cliente', 'is_active' => true]);
        $order = $this->order($branch, $employee, $customer, Order::STATUS_CONFIRMED, 210000);

        foreach (range(1, 6) as $position) {
            $this->productItem(
                $order,
                $this->product("Producto {$position}", "SKU-{$position}"),
                $position,
                10000,
                1000,
            );
        }

        Sanctum::actingAs($user);
        $this->getJson('/api/my/business-overview')->assertOk()
            ->assertJsonCount(5, 'data.company.top_products')
            ->assertJsonCount(5, 'data.my_branch.top_products')
            ->assertJsonPath('data.company.top_products.0.product_name', 'Producto 6')
            ->assertJsonPath('data.company.top_products.0.units', 6);
    }

    private function user(string $name = 'Colaborador'): User
    {
        return User::factory()->create([
            'name' => $name,
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);
    }

    private function grant(User $user, array $capabilities): void
    {
        foreach ($capabilities as $capability) {
            UserCapability::create(['user_id' => $user->id, 'capability' => $capability]);
        }
        $user->unsetRelation('capabilities');
    }

    private function branch(string $code): Branch
    {
        return Branch::create([
            'code' => $code,
            'slug' => strtolower($code).'-'.uniqid(),
            'name' => "Sede {$code}",
            'city' => $code === 'BAQ' ? 'Barranquilla' : 'Bogotá',
            'is_active' => true,
        ]);
    }

    private function employee(Branch $branch, ?User $user = null, string $name = 'Colaborador'): Employee
    {
        return Employee::create([
            'user_id' => $user?->id,
            'branch_id' => $branch->id,
            'name' => $name,
            'job_title' => 'Asesor',
            'is_active' => true,
        ]);
    }

    private function product(string $name = 'Producto', string $sku = 'SKU-1'): Product
    {
        return Product::create([
            'name' => $name,
            'slug' => strtolower($name).'-'.uniqid(),
            'sku' => $sku.'-'.uniqid(),
            'price' => 100000,
            'cost_price' => 40000,
            'is_active' => true,
            'is_visible' => true,
        ]);
    }

    private function order(
        Branch $branch,
        Employee $employee,
        Customer $customer,
        string $status,
        int $total,
        string $confirmedAt = '2026-08-24 12:00:00',
    ): Order {
        return Order::create([
            'order_number' => $branch->code.'-'.uniqid(),
            'branch_id' => $branch->id,
            'sales_employee_id' => $employee->id,
            'origin' => Order::ORIGIN_CRM,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'subtotal' => $total,
            'discount_total' => 0,
            'total' => $total,
            'status' => $status,
            'payment_status' => Order::PAYMENT_UNPAID,
            'confirmed_at' => $confirmedAt,
        ]);
    }

    private function productItem(Order $order, Product $product, int $quantity, int $unitPrice, int $unitCost): OrderItem
    {
        return OrderItem::create([
            'order_id' => $order->id,
            'item_type' => OrderItem::ITEM_TYPE_PRODUCT,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_sku' => $product->sku,
            'unit_price' => $unitPrice,
            'unit_cost' => $unitCost,
            'quantity' => $quantity,
            'subtotal' => $unitPrice * $quantity,
            'discount_amount' => 0,
            'total' => $unitPrice * $quantity,
        ]);
    }

    private function serviceItem(Order $order, int $quantity): void
    {
        OrderItem::create([
            'order_id' => $order->id,
            'item_type' => OrderItem::ITEM_TYPE_SERVICE,
            'service_name' => 'Instalación',
            'unit_price' => 0,
            'quantity' => $quantity,
            'subtotal' => 0,
            'discount_amount' => 0,
            'total' => 0,
        ]);
    }

    private function quotation(Branch $branch, Employee $employee, string $status, string $validUntil): Quotation
    {
        return Quotation::create([
            'quotation_number' => 'Q-'.$branch->code.'-'.uniqid(),
            'branch_id' => $branch->id,
            'sales_employee_id' => $employee->id,
            'status' => $status,
            'valid_until' => $validUntil,
            'subtotal' => 100000,
            'discount_total' => 0,
            'total' => 100000,
            'created_at' => '2026-08-10 12:00:00',
        ]);
    }

    private function commission(
        Employee $employee,
        Branch $branch,
        string $status,
        int $amount,
        int $quantity,
        string $eventAt,
    ): EmployeeCommission {
        $customer = Customer::create(['name' => 'Cliente comisión '.uniqid(), 'is_active' => true]);
        $order = $this->order($branch, $employee, $customer, Order::STATUS_PENDING, 1);
        $item = $this->productItem($order, $this->product('Comisionable', 'COM'), 1, 1, 1);

        return EmployeeCommission::create([
            'employee_id' => $employee->id,
            'branch_id' => $branch->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'product_id' => $item->product_id,
            'product_name_snapshot' => $item->product_name,
            'sku_snapshot' => $item->product_sku,
            'quantity' => $quantity,
            'unit_commission' => intdiv($amount, $quantity),
            'amount' => $amount,
            'status' => $status,
            'earned_at' => $status === EmployeeCommission::STATUS_EARNED ? $eventAt : null,
            'voided_at' => $status === EmployeeCommission::STATUS_VOIDED ? $eventAt : null,
            'void_reason' => $status === EmployeeCommission::STATUS_VOIDED
                ? EmployeeCommission::VOID_REASON_ORDER_CANCELLED
                : null,
            'created_at' => $eventAt,
        ]);
    }
}
