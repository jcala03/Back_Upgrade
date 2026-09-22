<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeesPhaseOneTest extends TestCase
{
    use RefreshDatabase;

    public function createApplication()
    {
        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        if ($connection = getenv('TEST_DB_CONNECTION')) {
            $app['config']->set('database.default', $connection);
            $app['config']->set("database.connections.{$connection}.database", getenv('TEST_DB_DATABASE') ?: 'upgrade_employees_test');
        }

        return $app;
    }

    public function test_permissions_allow_sales_read_only_and_require_authentication(): void
    {
        $employee = Employee::create($this->employeeData());

        $this->getJson('/api/admin/employees')->assertUnauthorized();
        $this->postJson('/api/admin/employees', $this->employeeData())->assertUnauthorized();

        $this->actingAsRole('user');
        $this->getJson('/api/admin/employees')->assertForbidden();
        $this->getJson("/api/admin/employees/{$employee->id}")->assertForbidden();
        $this->postJson('/api/admin/employees', $this->employeeData())->assertForbidden();
        $this->patchJson("/api/admin/employees/{$employee->id}", ['name' => 'No autorizado'])->assertForbidden();
        $this->deleteJson("/api/admin/employees/{$employee->id}")->assertForbidden();
    }

    public function test_admin_manages_employee_without_user_and_delete_is_reversible_deactivation(): void
    {
        $this->actingAsRole('admin');

        $employeeId = $this->postJson('/api/admin/employees', $this->employeeData())
            ->assertCreated()
            ->assertJsonPath('data.user_id', null)
            ->assertJsonPath('data.user', null)
            ->assertJsonPath('data.is_active', true)
            ->json('data.id');

        $this->getJson("/api/admin/employees/{$employeeId}")
            ->assertOk()->assertJsonPath('data.name', 'Ana Técnica');
        $this->patchJson("/api/admin/employees/{$employeeId}", ['phone' => '3110000000'])
            ->assertOk()->assertJsonPath('data.phone', '3110000000');
        $this->deleteJson("/api/admin/employees/{$employeeId}")
            ->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertDatabaseHas('employees', ['id' => $employeeId, 'is_active' => false]);
        $this->patchJson("/api/admin/employees/{$employeeId}", ['is_active' => true])
            ->assertOk()->assertJsonPath('data.is_active', true);
    }

    public function test_user_link_is_optional_unique_changeable_and_safe_in_responses(): void
    {
        $this->actingAsRole('admin');
        $firstUser = User::factory()->create(['role' => 'user', 'is_active' => false]);
        $secondUser = User::factory()->create(['role' => 'user']);

        $employeeId = $this->postJson('/api/admin/employees', [...$this->employeeData(), 'user_id' => $firstUser->id])
            ->assertCreated()
            ->assertJsonPath('data.user.id', $firstUser->id)
            ->assertJsonPath('data.user.is_active', false)
            ->assertJsonMissingPath('data.user.password')
            ->assertJsonMissingPath('data.user.remember_token')
            ->assertJsonMissingPath('data.user.role')
            ->json('data.id');

        $this->postJson('/api/admin/employees', [...$this->employeeData(), 'name' => 'Duplicado', 'user_id' => $firstUser->id])
            ->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->postJson('/api/admin/employees', [...$this->employeeData(), 'name' => 'Inexistente', 'user_id' => 999999])
            ->assertUnprocessable()->assertJsonValidationErrors('user_id');

        $this->patchJson("/api/admin/employees/{$employeeId}", ['user_id' => $secondUser->id])
            ->assertOk()->assertJsonPath('data.user.id', $secondUser->id);
        $this->patchJson("/api/admin/employees/{$employeeId}", ['user_id' => null])
            ->assertOk()->assertJsonPath('data.user_id', null)->assertJsonPath('data.user', null);
    }

    public function test_employee_and_user_active_states_are_independent_and_link_survives_deactivation(): void
    {
        $this->actingAsRole('admin');
        $user = User::factory()->create(['role' => 'user', 'is_active' => true]);
        $employee = Employee::create([...$this->employeeData(), 'user_id' => $user->id]);

        $this->deleteJson("/api/admin/employees/{$employee->id}")->assertOk();
        $this->assertDatabaseHas('employees', ['id' => $employee->id, 'user_id' => $user->id, 'is_active' => false]);
        $this->assertTrue($user->fresh()->is_active);

        $user->update(['is_active' => false]);
        $employee->update(['is_active' => true]);
        $this->assertTrue($employee->fresh()->is_active);
        $this->assertFalse($user->fresh()->is_active);
        $this->assertTrue(Employee::query()->active()->whereKey($employee->id)->exists());
    }

    public function test_list_supports_search_filters_link_state_pagination_and_allowed_sorting(): void
    {
        $this->actingAsRole('admin');
        $user = User::factory()->create(['role' => 'user']);
        Employee::create([...$this->employeeData(), 'name' => 'Zoe Audio', 'phone' => '3001112222', 'job_title' => 'Instaladora', 'specialty' => 'Audio', 'user_id' => $user->id, 'hire_date' => '2026-02-01']);
        Employee::create([...$this->employeeData(), 'name' => 'Ana Cámaras', 'phone' => '3109998888', 'job_title' => 'Asesora', 'specialty' => 'Cámaras', 'is_active' => false, 'hire_date' => '2025-01-01']);
        Employee::create([...$this->employeeData(), 'name' => 'Bea Multimedia', 'job_title' => 'Instaladora', 'specialty' => 'Multimedia']);

        foreach (['Zoe', '300111', 'Instaladora', 'Audio'] as $search) {
            $this->getJson('/api/admin/employees?search='.urlencode($search))->assertOk()->assertJsonPath('data.total', $search === 'Instaladora' ? 2 : 1);
        }
        $this->getJson('/api/admin/employees?is_active=0')->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.name', 'Ana Cámaras');
        $this->getJson('/api/admin/employees?job_title=Instaladora')->assertJsonPath('data.total', 2);
        $this->getJson('/api/admin/employees?specialty=Cámaras')->assertJsonPath('data.total', 1);
        $this->getJson('/api/admin/employees?linked_user=1')->assertJsonPath('data.total', 1);
        $this->getJson('/api/admin/employees?linked_user=0')->assertJsonPath('data.total', 2);
        $this->getJson('/api/admin/employees?sort=hire_date&direction=asc&per_page=2')
            ->assertOk()->assertJsonPath('data.per_page', 2)->assertJsonCount(2, 'data.data');
        $this->getJson('/api/admin/employees?sort=not_allowed')->assertUnprocessable();
    }

    public function test_store_and_update_validation_enforces_the_public_contract(): void
    {
        $this->actingAsRole('admin');
        $this->postJson('/api/admin/employees', [])->assertUnprocessable()->assertJsonValidationErrors(['name', 'job_title']);
        $this->postJson('/api/admin/employees', [...$this->employeeData(), 'name' => str_repeat('a', 161), 'phone' => str_repeat('1', 41)])
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'phone']);

        $employee = Employee::create($this->employeeData());
        $this->patchJson("/api/admin/employees/{$employee->id}", ['name' => 'Nombre actualizado', 'hire_date' => 'fecha-invalida'])
            ->assertUnprocessable()->assertJsonValidationErrors('hire_date');
        $this->patchJson("/api/admin/employees/{$employee->id}", ['name' => 'Nombre actualizado'])
            ->assertOk()->assertJsonPath('data.job_title', 'Instaladora');
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role, 'is_active' => true]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function employeeData(): array
    {
        return [
            'name' => 'Ana Técnica',
            'phone' => '3000000000',
            'job_title' => 'Instaladora',
            'specialty' => 'Multimedia',
            'notes' => 'Turno diurno',
            'hire_date' => '2026-01-15',
        ];
    }
}
