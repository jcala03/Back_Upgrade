<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\InventoryItem;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\User;
use App\Models\UserCapability;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class QuotationBranchOwnershipPhaseThreeTest extends TestCase
{
    use RefreshDatabase;

    public function createApplication()
    {
        $connection = trim((string) getenv('TEST_DB_CONNECTION'));
        $database = trim((string) getenv('TEST_DB_DATABASE'));

        if ($connection !== 'mysql' || $database === '') {
            throw new RuntimeException('QuotationBranchOwnershipPhaseThreeTest requiere TEST_DB_CONNECTION=mysql y TEST_DB_DATABASE explícita.');
        }
        if (strcasecmp($database, 'upgrade') === 0) {
            throw new RuntimeException('QuotationBranchOwnershipPhaseThreeTest no puede ejecutarse contra la base principal upgrade.');
        }

        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $app['config']->set('database.default', $connection);
        $app['config']->set("database.connections.{$connection}.database", $database);

        return $app;
    }

    public function test_admin_assignment_requires_active_branch_and_same_branch_active_seller(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));
        $product = $this->product();
        $baq = $this->branch('BAQ');
        $bog = $this->branch('BOG');
        $inactive = $this->branch('INA', false);
        $baqSeller = $this->employee($baq, 'Vendedor BAQ');
        $bogSeller = $this->employee($bog, 'Vendedor BOG');
        $inactiveSeller = $this->employee($baq, 'Vendedor inactivo');
        $inactiveSeller->update(['is_active' => false]);

        $this->postJson('/api/admin/quotations', $this->payload($product, []))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('branch_id');
        $this->postJson('/api/admin/quotations', $this->payload($product, [
            'branch_id' => $inactive->id,
        ]))->assertUnprocessable()->assertJsonValidationErrors('branch_id');
        $this->postJson('/api/admin/quotations', $this->payload($product, [
            'branch_id' => $baq->id,
            'sales_employee_id' => $bogSeller->id,
        ]))->assertUnprocessable()->assertJsonValidationErrors('sales_employee_id');
        $this->postJson('/api/admin/quotations', $this->payload($product, [
            'branch_id' => $baq->id,
            'sales_employee_id' => $inactiveSeller->id,
        ]))->assertUnprocessable()->assertJsonValidationErrors('sales_employee_id');

        $created = $this->postJson('/api/admin/quotations', $this->payload($product, [
            'branch_id' => $baq->id,
            'sales_employee_id' => $baqSeller->id,
        ]))->assertCreated()
            ->assertJsonPath('data.branch.id', $baq->id)
            ->assertJsonPath('data.branch.code', 'BAQ')
            ->assertJsonPath('data.sales_employee.id', $baqSeller->id);
        $quotationId = $created->json('data.id');

        $this->getJson("/api/admin/quotations?branch_id={$baq->id}&sales_employee_id={$baqSeller->id}")
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.id', $quotationId);
        $this->getJson("/api/admin/quotations?branch_id={$bog->id}")
            ->assertOk()
            ->assertJsonPath('data.total', 0);

        $this->patchJson("/api/admin/quotations/{$quotationId}", $this->payload($product, [
            'branch_id' => $bog->id,
            'sales_employee_id' => $bogSeller->id,
        ]))->assertOk()
            ->assertJsonPath('data.branch.id', $bog->id)
            ->assertJsonPath('data.sales_employee.id', $bogSeller->id);
        $this->postJson("/api/admin/quotations/{$quotationId}/status", ['status' => 'sent'])->assertOk();
        $this->patchJson("/api/admin/quotations/{$quotationId}", $this->payload($product, [
            'branch_id' => $baq->id,
            'sales_employee_id' => $baqSeller->id,
        ]))->assertUnprocessable()->assertJsonValidationErrors(['branch_id', 'sales_employee_id']);

        $this->assertDatabaseHas('quotations', [
            'id' => $quotationId,
            'branch_id' => $bog->id,
            'sales_employee_id' => $bogSeller->id,
        ]);
    }

    public function test_my_quotations_require_capabilities_derive_context_and_enforce_ownership(): void
    {
        $branch = $this->branch('BAQ');
        $product = $this->product();
        $user = User::factory()->create(['role' => User::ROLE_USER, 'is_active' => true]);
        $employee = $this->employee($branch, 'Propietario', $user);

        Sanctum::actingAs($user);
        $this->getJson('/api/my/quotations')->assertForbidden();
        $this->postJson('/api/my/quotations', $this->payload($product, []))->assertForbidden();

        $this->grant($user, [
            UserCapability::QUOTATIONS_VIEW_OWN,
            UserCapability::QUOTATIONS_CREATE_OWN,
            UserCapability::QUOTATIONS_UPDATE_OWN,
            UserCapability::QUOTATIONS_SEND_OWN,
            UserCapability::QUOTATIONS_CONVERT_OWN,
        ]);
        $user = $user->fresh();
        Sanctum::actingAs($user);

        $this->postJson('/api/my/quotations', $this->payload($product, [
            'branch_id' => $branch->id,
        ]))->assertUnprocessable()->assertJsonValidationErrors('branch_id');
        $this->postJson('/api/my/quotations', $this->payload($product, [
            'sales_employee_id' => $employee->id,
        ]))->assertUnprocessable()->assertJsonValidationErrors('sales_employee_id');

        $own = $this->postJson('/api/my/quotations', $this->payload($product, [
            'customer_name' => 'Cliente propio',
            'customer_document' => 'NO-EXPONER',
            'customer_address' => 'NO-EXPONER',
            'vehicle_vin' => 'NO-EXPONER',
        ]))->assertCreated()
            ->assertJsonPath('data.branch.id', $branch->id)
            ->assertJsonPath('data.sales_employee.id', $employee->id)
            ->assertJsonMissingPath('data.items.0.unit_cost')
            ->assertJsonMissingPath('data.created_by')
            ->assertJsonMissingPath('data.customer.document')
            ->assertJsonMissingPath('data.customer.address')
            ->assertJsonMissingPath('data.vehicle.vin');
        $ownId = $own->json('data.id');

        $otherUser = User::factory()->create(['role' => User::ROLE_USER]);
        $otherEmployee = $this->employee($branch, 'Otro vendedor', $otherUser);
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));
        $foreignId = $this->postJson('/api/admin/quotations', $this->payload($product, [
            'branch_id' => $branch->id,
            'sales_employee_id' => $otherEmployee->id,
            'customer_name' => 'Ajena',
        ]))->assertCreated()->json('data.id');

        Sanctum::actingAs($user);
        $this->getJson('/api/my/quotations')->assertOk()
            ->assertJsonPath('data.employee_linked', true)
            ->assertJsonPath('data.quotations.total', 1)
            ->assertJsonPath('data.quotations.data.0.id', $ownId);
        $this->getJson("/api/my/quotations/{$foreignId}")->assertNotFound();
        $this->patchJson("/api/my/quotations/{$foreignId}", $this->payload($product, []))->assertNotFound();
        $this->postJson("/api/my/quotations/{$foreignId}/send")->assertNotFound();
        $this->postJson("/api/my/quotations/{$foreignId}/convert")->assertNotFound();

        $this->patchJson("/api/my/quotations/{$ownId}", $this->payload($product, [
            'notes' => 'Actualizada por propietario',
        ]))->assertOk()->assertJsonPath('data.notes', 'Actualizada por propietario');
        $this->postJson("/api/my/quotations/{$ownId}/send")
            ->assertOk()
            ->assertJsonPath('data.status', Quotation::STATUS_SENT);
    }

    public function test_my_mutations_require_active_employee_branch_and_preserve_snapshot_after_transfer(): void
    {
        $baq = $this->branch('BAQ');
        $bog = $this->branch('BOG');
        $product = $this->product();

        $withoutEmployee = $this->commercialUser([UserCapability::QUOTATIONS_CREATE_OWN]);
        Sanctum::actingAs($withoutEmployee);
        $this->postJson('/api/my/quotations', $this->payload($product, []))
            ->assertUnprocessable()->assertJsonValidationErrors('employee');

        $withoutBranch = $this->commercialUser([UserCapability::QUOTATIONS_CREATE_OWN]);
        Employee::create([
            'user_id' => $withoutBranch->id,
            'name' => 'Sin sede',
            'job_title' => 'Asesor',
            'is_active' => true,
        ]);
        Sanctum::actingAs($withoutBranch);
        $this->postJson('/api/my/quotations', $this->payload($product, []))
            ->assertUnprocessable()->assertJsonValidationErrors('branch_id');

        $inactiveEmployeeUser = $this->commercialUser([UserCapability::QUOTATIONS_CREATE_OWN]);
        $inactiveEmployee = $this->employee($baq, 'Empleado inactivo', $inactiveEmployeeUser);
        $inactiveEmployee->update(['is_active' => false]);
        Sanctum::actingAs($inactiveEmployeeUser);
        $this->postJson('/api/my/quotations', $this->payload($product, []))
            ->assertUnprocessable()->assertJsonValidationErrors('employee');

        $inactiveBranch = $this->branch('INA', false);
        $inactiveBranchUser = $this->commercialUser([UserCapability::QUOTATIONS_CREATE_OWN]);
        $this->employee($inactiveBranch, 'Sede inactiva', $inactiveBranchUser);
        Sanctum::actingAs($inactiveBranchUser);
        $this->postJson('/api/my/quotations', $this->payload($product, []))
            ->assertUnprocessable()->assertJsonValidationErrors('branch_id');

        $user = $this->commercialUser([
            UserCapability::QUOTATIONS_CREATE_OWN,
            UserCapability::QUOTATIONS_UPDATE_OWN,
            UserCapability::QUOTATIONS_SEND_OWN,
            UserCapability::QUOTATIONS_CONVERT_OWN,
        ]);
        $employee = $this->employee($baq, 'Movido', $user);
        Sanctum::actingAs($user);
        $quotationId = $this->postJson('/api/my/quotations', $this->payload($product, []))
            ->assertCreated()->json('data.id');
        $employee->update(['branch_id' => $bog->id]);

        $this->patchJson("/api/my/quotations/{$quotationId}", $this->payload($product, []))
            ->assertUnprocessable()->assertJsonValidationErrors('branch_id');
        $this->postJson("/api/my/quotations/{$quotationId}/send")
            ->assertUnprocessable()->assertJsonValidationErrors('branch_id');
        $this->postJson("/api/my/quotations/{$quotationId}/convert")
            ->assertUnprocessable()->assertJsonValidationErrors('branch_id');
        $this->assertDatabaseHas('quotations', [
            'id' => $quotationId,
            'branch_id' => $baq->id,
            'sales_employee_id' => $employee->id,
            'status' => Quotation::STATUS_DRAFT,
        ]);
    }

    public function test_my_quotation_mutations_require_their_independent_capabilities(): void
    {
        $branch = $this->branch('BAQ');
        $product = $this->product();
        $user = $this->commercialUser([UserCapability::QUOTATIONS_VIEW_OWN]);
        $employee = $this->employee($branch, 'Solo lectura', $user);
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));
        $quotationId = $this->postJson('/api/admin/quotations', $this->payload($product, [
            'branch_id' => $branch->id,
            'sales_employee_id' => $employee->id,
        ]))->assertCreated()->json('data.id');

        Sanctum::actingAs($user);
        $this->patchJson("/api/my/quotations/{$quotationId}", $this->payload($product, []))
            ->assertForbidden();
        $this->postJson("/api/my/quotations/{$quotationId}/send")->assertForbidden();
        $this->postJson("/api/my/quotations/{$quotationId}/convert")->assertForbidden();
    }

    public function test_user_without_employee_cannot_show_a_sellerless_quotation(): void
    {
        $user = $this->commercialUser([UserCapability::QUOTATIONS_VIEW_OWN]);
        $quotation = Quotation::create([
            'quotation_number' => 'COT-LEGACY-'.uniqid(),
            'status' => Quotation::STATUS_DRAFT,
            'valid_until' => now()->subDay()->toDateString(),
            'subtotal' => 100000,
            'discount_total' => 0,
            'total' => 100000,
        ]);
        Sanctum::actingAs($user);

        $this->getJson("/api/my/quotations/{$quotation->id}")->assertNotFound();
        $this->assertSame(Quotation::STATUS_DRAFT, $quotation->fresh()->status);
    }

    public function test_conversion_propagates_assignment_and_consumes_only_quotation_branch_stock(): void
    {
        $baq = $this->branch('BAQ');
        $bog = $this->branch('BOG');
        $product = $this->product();
        $user = $this->commercialUser([
            UserCapability::QUOTATIONS_CREATE_OWN,
            UserCapability::QUOTATIONS_CONVERT_OWN,
        ]);
        $employee = $this->employee($bog, 'Vendedor BOG', $user);
        $item = InventoryItem::create(['product_id' => $product->id]);
        $baqStock = InventoryStock::create([
            'branch_id' => $baq->id,
            'inventory_item_id' => $item->id,
            'quantity' => 20,
        ]);
        $bogStock = InventoryStock::create([
            'branch_id' => $bog->id,
            'inventory_item_id' => $item->id,
            'quantity' => 1,
        ]);

        Sanctum::actingAs($user);
        $quotationId = $this->postJson('/api/my/quotations', $this->payload($product, [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ]))->assertCreated()->json('data.id');

        $this->postJson("/api/my/quotations/{$quotationId}/convert")
            ->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->assertSame(20, $baqStock->fresh()->quantity);
        $this->assertSame(1, $bogStock->fresh()->quantity);
        $this->assertDatabaseCount('orders', 0);

        $bogStock->update(['quantity' => 3]);
        $response = $this->postJson("/api/my/quotations/{$quotationId}/convert")
            ->assertCreated()
            ->assertJsonPath('data.status', Quotation::STATUS_CONVERTED)
            ->assertJsonMissingPath('data.items.0.unit_cost');
        $order = Order::findOrFail($response->json('data.order.id'));
        $this->assertSame($bog->id, $order->branch_id);
        $this->assertSame($employee->id, $order->sales_employee_id);
        $this->assertSame(20, $baqStock->fresh()->quantity);
        $this->assertSame(1, $bogStock->fresh()->quantity);
        $this->assertDatabaseHas('inventory_movements', [
            'branch_id' => $bog->id,
            'inventory_item_id' => $item->id,
            'type' => 'sale',
            'reference_type' => Order::class,
            'reference_id' => $order->id,
        ]);
    }

    private function branch(string $code, bool $active = true): Branch
    {
        return Branch::create([
            'code' => $code,
            'slug' => strtolower($code).'-'.uniqid(),
            'name' => "Sede {$code}",
            'city' => $code === 'BOG' ? 'Bogotá' : 'Barranquilla',
            'is_active' => $active,
        ]);
    }

    private function employee(Branch $branch, string $name, ?User $user = null): Employee
    {
        return Employee::create([
            'user_id' => $user?->id,
            'branch_id' => $branch->id,
            'name' => $name,
            'job_title' => 'Asesor',
            'is_active' => true,
        ]);
    }

    private function product(): Product
    {
        return Product::create([
            'name' => 'Producto '.uniqid(),
            'slug' => 'producto-'.uniqid(),
            'sku' => 'P-'.uniqid(),
            'price' => 100000,
            'cost_price' => 40000,
            'is_active' => true,
            'is_visible' => true,
        ]);
    }

    private function payload(Product $product, array $overrides): array
    {
        return [...[
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ], ...$overrides];
    }

    /**
     * @param  array<int, string>  $capabilities
     */
    private function commercialUser(array $capabilities): User
    {
        $user = User::factory()->create(['role' => User::ROLE_USER, 'is_active' => true]);
        $this->grant($user, $capabilities);

        return $user->fresh();
    }

    /**
     * @param  array<int, string>  $capabilities
     */
    private function grant(User $user, array $capabilities): void
    {
        foreach ($capabilities as $capability) {
            UserCapability::create([
                'user_id' => $user->id,
                'capability' => $capability,
            ]);
        }
    }
}
