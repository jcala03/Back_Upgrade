<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeBranchAssignment;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\BranchSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class BranchCorePhaseOneTest extends TestCase
{
    use RefreshDatabase;

    public function createApplication()
    {
        $connection = trim((string) getenv('TEST_DB_CONNECTION'));
        $database = trim((string) getenv('TEST_DB_DATABASE'));

        if ($connection !== 'mysql' || $database === '') {
            throw new RuntimeException('BranchCorePhaseOneTest requiere TEST_DB_CONNECTION=mysql y TEST_DB_DATABASE explícita.');
        }

        if (strcasecmp($database, 'upgrade') === 0) {
            throw new RuntimeException('BranchCorePhaseOneTest no puede ejecutarse contra la base principal upgrade.');
        }

        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $app['config']->set('database.default', $connection);
        $app['config']->set("database.connections.{$connection}.database", $database);

        if (strcasecmp((string) $app['config']->get("database.connections.{$connection}.database"), 'upgrade') === 0) {
            throw new RuntimeException('La base configurada para BranchCorePhaseOneTest no puede ser upgrade.');
        }

        return $app;
    }

    public function test_guest_cannot_access_branch_administration(): void
    {
        $branch = $this->branch();

        $this->getJson('/api/admin/branches')->assertUnauthorized();
        $this->postJson('/api/admin/branches', $this->branchData('MED'))->assertUnauthorized();
        $this->getJson("/api/admin/branches/{$branch->id}")->assertUnauthorized();
        $this->patchJson("/api/admin/branches/{$branch->id}", ['name' => 'Sin autorización'])->assertUnauthorized();
    }

    public function test_role_user_cannot_access_branch_administration(): void
    {
        $branch = $this->branch();
        $this->actingAsRole(User::ROLE_USER);

        $this->getJson('/api/admin/branches')->assertForbidden();
        $this->postJson('/api/admin/branches', $this->branchData('MED'))->assertForbidden();
        $this->getJson("/api/admin/branches/{$branch->id}")->assertForbidden();
        $this->patchJson("/api/admin/branches/{$branch->id}", ['name' => 'Sin autorización'])->assertForbidden();
    }

    public function test_admin_can_list_branches(): void
    {
        $this->actingAsRole(User::ROLE_ADMIN);
        $bogota = $this->branch(['code' => 'BOG', 'slug' => 'bogota', 'name' => 'Bogotá', 'city' => 'Bogotá']);
        $barranquilla = $this->branch();

        $this->getJson('/api/admin/branches')
            ->assertOk()
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.data.0.id', $barranquilla->id)
            ->assertJsonPath('data.data.1.id', $bogota->id);
    }

    public function test_admin_can_create_branch_with_normalized_code_and_slug(): void
    {
        $this->actingAsRole(User::ROLE_ADMIN);

        $this->postJson('/api/admin/branches', [
            'code' => ' baq ',
            'slug' => ' Barranquilla Norte ',
            'name' => 'Barranquilla Norte',
            'city' => 'Barranquilla',
        ])->assertCreated()
            ->assertJsonPath('message', 'Sede creada correctamente.')
            ->assertJsonPath('data.code', 'BAQ')
            ->assertJsonPath('data.slug', 'barranquilla-norte')
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('branches', [
            'code' => 'BAQ',
            'slug' => 'barranquilla-norte',
            'name' => 'Barranquilla Norte',
            'is_active' => true,
        ]);
    }

    public function test_duplicate_branch_code_returns_validation_error(): void
    {
        $this->branch();
        $this->actingAsRole(User::ROLE_ADMIN);

        $this->postJson('/api/admin/branches', $this->branchData('baq'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
    }

    public function test_duplicate_branch_slug_returns_validation_error(): void
    {
        $this->branch();
        $this->actingAsRole(User::ROLE_ADMIN);

        $this->postJson('/api/admin/branches', [
            ...$this->branchData('MED'),
            'slug' => 'Barranquilla',
        ])->assertUnprocessable()->assertJsonValidationErrors('slug');
    }

    public function test_admin_can_show_branch(): void
    {
        $branch = $this->branch();
        $this->actingAsRole(User::ROLE_ADMIN);

        $this->getJson("/api/admin/branches/{$branch->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $branch->id)
            ->assertJsonPath('data.code', 'BAQ')
            ->assertJsonPath('data.slug', 'barranquilla')
            ->assertJsonPath('data.name', 'Barranquilla')
            ->assertJsonPath('data.city', 'Barranquilla')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonMissingPath('data.employees');
    }

    public function test_admin_can_update_mutable_branch_fields(): void
    {
        $branch = $this->branch();
        $this->actingAsRole(User::ROLE_ADMIN);

        $this->patchJson("/api/admin/branches/{$branch->id}", [
            'slug' => ' Barranquilla Principal ',
            'name' => 'Barranquilla Principal',
            'city' => 'Soledad',
        ])->assertOk()
            ->assertJsonPath('message', 'Sede actualizada correctamente.')
            ->assertJsonPath('data.code', 'BAQ')
            ->assertJsonPath('data.slug', 'barranquilla-principal')
            ->assertJsonPath('data.name', 'Barranquilla Principal')
            ->assertJsonPath('data.city', 'Soledad');

        $this->assertDatabaseHas('branches', [
            'id' => $branch->id,
            'code' => 'BAQ',
            'slug' => 'barranquilla-principal',
        ]);
    }

    public function test_branch_code_is_not_editable(): void
    {
        $branch = $this->branch();
        $this->actingAsRole(User::ROLE_ADMIN);

        $this->patchJson("/api/admin/branches/{$branch->id}", ['code' => 'BOG'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        $this->assertSame('BAQ', $branch->fresh()->code);
    }

    public function test_admin_can_deactivate_branch_without_active_employees(): void
    {
        $branch = $this->branch();
        $this->actingAsRole(User::ROLE_ADMIN);

        $this->patchJson("/api/admin/branches/{$branch->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('branches', ['id' => $branch->id, 'is_active' => false]);
    }

    public function test_branch_with_active_employee_cannot_be_deactivated(): void
    {
        $branch = $this->branch();
        Employee::create([...$this->employeeData(), 'branch_id' => $branch->id]);
        $this->actingAsRole(User::ROLE_ADMIN);

        $this->patchJson("/api/admin/branches/{$branch->id}", ['is_active' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_active');

        $this->assertTrue($branch->fresh()->is_active);
    }

    public function test_branches_do_not_expose_delete_route(): void
    {
        $branch = $this->branch();
        $this->actingAsRole(User::ROLE_ADMIN);

        $this->deleteJson("/api/admin/branches/{$branch->id}")->assertMethodNotAllowed();
        $this->assertDatabaseHas('branches', ['id' => $branch->id]);
    }

    public function test_inactive_branch_remains_available_for_admin_history(): void
    {
        $branch = $this->branch(['is_active' => false]);
        $this->actingAsRole(User::ROLE_ADMIN);

        $this->getJson('/api/admin/branches?is_active=0')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.id', $branch->id)
            ->assertJsonPath('data.data.0.is_active', false);
        $this->getJson("/api/admin/branches/{$branch->id}")->assertOk();
    }

    public function test_employee_has_branch_relationship_and_branch_has_employees(): void
    {
        $branch = $this->branch();
        $employee = Employee::create([...$this->employeeData(), 'branch_id' => $branch->id]);

        $this->assertTrue($employee->branch->is($branch));
        $this->assertTrue($branch->employees()->whereKey($employee->id)->exists());
    }

    public function test_employee_response_includes_reduced_branch_payload(): void
    {
        $branch = $this->branch();
        $employee = Employee::create([...$this->employeeData(), 'branch_id' => $branch->id]);
        $this->actingAsRole(User::ROLE_ADMIN);

        $this->getJson("/api/admin/employees/{$employee->id}")
            ->assertOk()
            ->assertJsonPath('data.branch_id', $branch->id)
            ->assertJsonPath('data.branch.id', $branch->id)
            ->assertJsonPath('data.branch.code', 'BAQ')
            ->assertJsonPath('data.branch.name', 'Barranquilla')
            ->assertJsonPath('data.branch.city', 'Barranquilla')
            ->assertJsonPath('data.branch.is_active', true)
            ->assertJsonMissingPath('data.branch.slug');
    }

    public function test_employee_list_filters_by_branch_id(): void
    {
        $barranquilla = $this->branch();
        $bogota = $this->branch(['code' => 'BOG', 'slug' => 'bogota', 'name' => 'Bogotá', 'city' => 'Bogotá']);
        $expected = Employee::create([...$this->employeeData(), 'name' => 'Empleado BAQ', 'branch_id' => $barranquilla->id]);
        Employee::create([...$this->employeeData(), 'name' => 'Empleado BOG', 'branch_id' => $bogota->id]);
        Employee::create([...$this->employeeData(), 'name' => 'Empleado sin sede']);
        $this->actingAsRole(User::ROLE_ADMIN);

        $this->getJson("/api/admin/employees?branch_id={$barranquilla->id}")
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.id', $expected->id)
            ->assertJsonPath('data.data.0.branch.id', $barranquilla->id);
    }

    public function test_employee_branch_can_remain_null_during_transition(): void
    {
        $this->actingAsRole(User::ROLE_ADMIN);

        $employeeId = $this->postJson('/api/admin/employees', $this->employeeData())
            ->assertCreated()
            ->assertJsonPath('data.branch_id', null)
            ->assertJsonPath('data.branch', null)
            ->json('data.id');

        $this->assertDatabaseHas('employees', ['id' => $employeeId, 'branch_id' => null]);
        $this->assertDatabaseCount('employee_branch_assignments', 0);
    }

    public function test_employee_cannot_be_assigned_to_inactive_branch(): void
    {
        $branch = $this->branch(['is_active' => false]);
        $this->actingAsRole(User::ROLE_ADMIN);

        $this->postJson('/api/admin/employees', [
            ...$this->employeeData(),
            'branch_id' => $branch->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('branch_id');

        $this->assertDatabaseMissing('employees', ['name' => 'Ana Técnica']);

        $employee = Employee::create([...$this->employeeData(), 'name' => 'Empleado sin sede']);
        $this->patchJson("/api/admin/employees/{$employee->id}", ['branch_id' => $branch->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('branch_id');
        $this->assertNull($employee->fresh()->branch_id);
    }

    public function test_inactive_employee_cannot_be_reactivated_on_inactive_branch(): void
    {
        $branch = $this->branch();
        $employee = Employee::create([
            ...$this->employeeData(),
            'branch_id' => $branch->id,
            'is_active' => false,
        ]);
        $branch->update(['is_active' => false]);
        $this->actingAsRole(User::ROLE_ADMIN);

        $this->patchJson("/api/admin/employees/{$employee->id}", ['is_active' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('branch_id');

        $this->assertFalse($employee->fresh()->is_active);
        $this->assertFalse($branch->fresh()->is_active);

        $this->patchJson("/api/admin/employees/{$employee->id}", [
            'branch_id' => $branch->id,
            'phone' => '3010000000',
        ])->assertOk()
            ->assertJsonPath('data.branch_id', $branch->id)
            ->assertJsonPath('data.is_active', false);
    }

    public function test_assigning_employee_from_null_to_branch_records_history(): void
    {
        $branch = $this->branch();
        $admin = $this->actingAsRole(User::ROLE_ADMIN);
        $employeeId = $this->postJson('/api/admin/employees', $this->employeeData())
            ->assertCreated()->json('data.id');

        $this->patchJson("/api/admin/employees/{$employeeId}", ['branch_id' => $branch->id])
            ->assertOk()->assertJsonPath('data.branch_id', $branch->id);

        $this->assertDatabaseHas('employee_branch_assignments', [
            'employee_id' => $employeeId,
            'from_branch_id' => null,
            'to_branch_id' => $branch->id,
            'changed_by' => $admin->id,
        ]);
    }

    public function test_moving_employee_between_branches_records_history(): void
    {
        $from = $this->branch();
        $to = $this->branch(['code' => 'BOG', 'slug' => 'bogota', 'name' => 'Bogotá', 'city' => 'Bogotá']);
        $this->actingAsRole(User::ROLE_ADMIN);
        $employeeId = $this->postJson('/api/admin/employees', [
            ...$this->employeeData(),
            'branch_id' => $from->id,
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/admin/employees/{$employeeId}", ['branch_id' => $to->id])
            ->assertOk()->assertJsonPath('data.branch_id', $to->id);

        $this->assertDatabaseHas('employee_branch_assignments', [
            'employee_id' => $employeeId,
            'from_branch_id' => $from->id,
            'to_branch_id' => $to->id,
        ]);
        $this->assertDatabaseCount('employee_branch_assignments', 2);
    }

    public function test_employee_with_future_active_appointment_cannot_move_branches_silently(): void
    {
        $startsAt = now()->addDay()->startOfDay()->addHours(14);
        $from = $this->branch();
        $to = $this->branch(['code' => 'BOG', 'slug' => 'bogota', 'name' => 'Bogotá', 'city' => 'Bogotá']);
        $this->actingAsRole(User::ROLE_ADMIN);
        $employeeId = $this->postJson('/api/admin/employees', [
            ...$this->employeeData(),
            'branch_id' => $from->id,
        ])->assertCreated()->json('data.id');
        Appointment::create([
            'responsible_employee_id' => $employeeId,
            'branch_id' => $from->id,
            'source' => Appointment::SOURCE_CRM,
            'status' => Appointment::STATUS_CONFIRMED,
            'title' => 'Cita futura',
            'contact_name' => 'Cliente',
            'contact_phone' => '3000000000',
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHour(),
        ]);

        $this->patchJson("/api/admin/employees/{$employeeId}", ['branch_id' => $to->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('branch_id');

        $this->assertDatabaseHas('employees', ['id' => $employeeId, 'branch_id' => $from->id]);
        $this->assertDatabaseCount('employee_branch_assignments', 1);
    }

    public function test_employee_with_future_active_scheduled_task_cannot_move_branches_silently(): void
    {
        $startsAt = now()->addDay()->startOfDay()->addHours(14);
        $from = $this->branch();
        $to = $this->branch(['code' => 'BOG', 'slug' => 'bogota', 'name' => 'Bogotá', 'city' => 'Bogotá']);
        $this->actingAsRole(User::ROLE_ADMIN);
        $employeeId = $this->postJson('/api/admin/employees', [
            ...$this->employeeData(),
            'branch_id' => $from->id,
        ])->assertCreated()->json('data.id');
        Task::create([
            'assigned_employee_id' => $employeeId,
            'branch_id' => $from->id,
            'title' => 'Tarea futura',
            'priority' => Task::PRIORITY_NORMAL,
            'status' => Task::STATUS_PENDING,
            'scheduled_starts_at' => $startsAt,
            'scheduled_ends_at' => $startsAt->copy()->addHour(),
        ]);

        $this->patchJson("/api/admin/employees/{$employeeId}", ['branch_id' => $to->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('branch_id');

        $this->assertDatabaseHas('employees', ['id' => $employeeId, 'branch_id' => $from->id]);
        $this->assertDatabaseCount('employee_branch_assignments', 1);
    }

    public function test_past_terminal_unscheduled_and_legacy_tasks_do_not_block_employee_branch_move(): void
    {
        $from = $this->branch();
        $to = $this->branch(['code' => 'BOG', 'slug' => 'bogota', 'name' => 'Bogotá', 'city' => 'Bogotá']);
        $this->actingAsRole(User::ROLE_ADMIN);
        $employeeId = $this->postJson('/api/admin/employees', [
            ...$this->employeeData(),
            'branch_id' => $from->id,
        ])->assertCreated()->json('data.id');
        $historical = Task::create([
            'assigned_employee_id' => $employeeId,
            'branch_id' => $from->id,
            'title' => 'Tarea histórica',
            'priority' => Task::PRIORITY_NORMAL,
            'status' => Task::STATUS_COMPLETED,
            'scheduled_starts_at' => '2026-08-01 14:00:00',
            'scheduled_ends_at' => '2026-08-01 15:00:00',
        ]);
        Task::create([
            'assigned_employee_id' => $employeeId,
            'branch_id' => $from->id,
            'title' => 'Sin horario',
            'priority' => Task::PRIORITY_NORMAL,
            'status' => Task::STATUS_PENDING,
        ]);
        Task::create([
            'assigned_employee_id' => $employeeId,
            'branch_id' => null,
            'title' => 'Legacy futuro',
            'priority' => Task::PRIORITY_NORMAL,
            'status' => Task::STATUS_PENDING,
            'scheduled_starts_at' => '2026-10-01 16:00:00',
            'scheduled_ends_at' => '2026-10-01 17:00:00',
        ]);

        $this->patchJson("/api/admin/employees/{$employeeId}", ['branch_id' => $to->id])
            ->assertOk()
            ->assertJsonPath('data.branch_id', $to->id);

        $this->assertDatabaseHas('employee_branch_assignments', [
            'employee_id' => $employeeId,
            'from_branch_id' => $from->id,
            'to_branch_id' => $to->id,
        ]);
        $this->assertSame($from->id, $historical->fresh()->branch_id);
    }

    public function test_past_or_terminal_appointments_do_not_block_employee_branch_move(): void
    {
        $from = $this->branch();
        $to = $this->branch(['code' => 'BOG', 'slug' => 'bogota', 'name' => 'Bogotá', 'city' => 'Bogotá']);
        $this->actingAsRole(User::ROLE_ADMIN);
        $employeeId = $this->postJson('/api/admin/employees', [
            ...$this->employeeData(),
            'branch_id' => $from->id,
        ])->assertCreated()->json('data.id');
        $historical = Appointment::create([
            'responsible_employee_id' => $employeeId,
            'branch_id' => $from->id,
            'source' => Appointment::SOURCE_CRM,
            'status' => Appointment::STATUS_COMPLETED,
            'title' => 'Cita histórica',
            'contact_name' => 'Cliente',
            'contact_phone' => '3000000000',
            'starts_at' => '2026-08-01 14:00:00',
            'ends_at' => '2026-08-01 15:00:00',
        ]);

        $this->patchJson("/api/admin/employees/{$employeeId}", ['branch_id' => $to->id])
            ->assertOk()
            ->assertJsonPath('data.branch_id', $to->id);

        $this->assertDatabaseHas('employee_branch_assignments', [
            'employee_id' => $employeeId,
            'from_branch_id' => $from->id,
            'to_branch_id' => $to->id,
        ]);
        $this->assertSame($from->id, $historical->fresh()->branch_id);
    }

    public function test_reassigning_same_branch_does_not_duplicate_history(): void
    {
        $branch = $this->branch();
        $this->actingAsRole(User::ROLE_ADMIN);
        $employeeId = $this->postJson('/api/admin/employees', [
            ...$this->employeeData(),
            'branch_id' => $branch->id,
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/admin/employees/{$employeeId}", ['branch_id' => $branch->id])
            ->assertOk();

        $this->assertDatabaseCount('employee_branch_assignments', 1);
    }

    public function test_removing_branch_during_transition_records_history(): void
    {
        $branch = $this->branch();
        $this->actingAsRole(User::ROLE_ADMIN);
        $employeeId = $this->postJson('/api/admin/employees', [
            ...$this->employeeData(),
            'branch_id' => $branch->id,
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/admin/employees/{$employeeId}", ['branch_id' => null])
            ->assertOk()
            ->assertJsonPath('data.branch_id', null)
            ->assertJsonPath('data.branch', null);

        $this->assertDatabaseHas('employee_branch_assignments', [
            'employee_id' => $employeeId,
            'from_branch_id' => $branch->id,
            'to_branch_id' => null,
        ]);
        $this->assertDatabaseCount('employee_branch_assignments', 2);
    }

    public function test_history_changed_at_is_not_automatically_mutable_in_mariadb(): void
    {
        $column = DB::selectOne(<<<'SQL'
            SELECT DATA_TYPE, EXTRA
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'employee_branch_assignments'
              AND COLUMN_NAME = 'changed_at'
            SQL);

        $this->assertSame('datetime', strtolower((string) $column->DATA_TYPE));
        $this->assertStringNotContainsString('on update', strtolower((string) $column->EXTRA));
    }

    public function test_branch_history_records_the_authenticated_actor(): void
    {
        $from = $this->branch();
        $to = $this->branch(['code' => 'BOG', 'slug' => 'bogota', 'name' => 'Bogotá', 'city' => 'Bogotá']);
        $creator = $this->actingAsRole(User::ROLE_ADMIN);
        $employeeId = $this->postJson('/api/admin/employees', [
            ...$this->employeeData(),
            'branch_id' => $from->id,
        ])->assertCreated()->json('data.id');
        $updater = $this->actingAsRole(User::ROLE_ADMIN);

        $this->patchJson("/api/admin/employees/{$employeeId}", ['branch_id' => $to->id])->assertOk();

        $history = EmployeeBranchAssignment::query()
            ->where('employee_id', $employeeId)
            ->orderBy('id')
            ->get();
        $this->assertCount(2, $history);
        $this->assertSame($creator->id, $history[0]->changed_by);
        $this->assertSame($updater->id, $history[1]->changed_by);
        $this->assertNotNull($history[0]->changed_at);
        $this->assertNotNull($history[1]->changed_at);
    }

    public function test_base_user_has_no_branch_permissions(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER, 'is_active' => true]);

        foreach (['branches.view', 'branches.create', 'branches.update'] as $permission) {
            $this->assertFalse($user->hasPermission($permission));
            $this->assertNotContains($permission, $user->permissions());
        }
    }

    public function test_admin_has_all_branch_permissions(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);

        foreach (['branches.view', 'branches.create', 'branches.update'] as $permission) {
            $this->assertTrue($admin->hasPermission($permission));
            $this->assertContains($permission, $admin->permissions());
        }
    }

    public function test_branch_seeder_is_idempotent(): void
    {
        $this->seed(BranchSeeder::class);
        $this->seed(BranchSeeder::class);

        $this->assertDatabaseCount('branches', 2);
        $this->assertDatabaseHas('branches', [
            'code' => 'BAQ',
            'slug' => 'barranquilla',
            'name' => 'Barranquilla',
            'city' => 'Barranquilla',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('branches', [
            'code' => 'BOG',
            'slug' => 'bogota',
            'name' => 'Bogotá',
            'city' => 'Bogotá',
            'is_active' => true,
        ]);
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role, 'is_active' => true]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function branch(array $attributes = []): Branch
    {
        return Branch::create([
            ...$this->branchData(),
            ...$attributes,
        ]);
    }

    private function branchData(string $code = 'BAQ'): array
    {
        $normalized = strtolower($code);

        return [
            'code' => strtoupper($code),
            'slug' => $normalized === 'baq' ? 'barranquilla' : "sede-{$normalized}",
            'name' => $normalized === 'baq' ? 'Barranquilla' : "Sede {$code}",
            'city' => $normalized === 'baq' ? 'Barranquilla' : "Ciudad {$code}",
            'is_active' => true,
        ];
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
