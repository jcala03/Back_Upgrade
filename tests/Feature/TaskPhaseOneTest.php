<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CrmNotification;
use App\Models\Employee;
use App\Models\EmployeeWorkSchedule;
use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TaskPhaseOneTest extends TestCase
{
    use RefreshDatabase;

    public function createApplication()
    {
        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        if ($connection = getenv('TEST_DB_CONNECTION')) {
            $app['config']->set('database.default', $connection);
            $app['config']->set("database.connections.{$connection}.database", getenv('TEST_DB_DATABASE'));
        }

        return $app;
    }

    public function test_schema_constants_relations_and_permissions(): void
    {
        $this->assertTrue(Schema::hasTable('tasks'));
        foreach (['assigned_employee_id', 'branch_id', 'scheduled_starts_at', 'availability_override', 'availability_overridden_by'] as $column) {
            $this->assertTrue(Schema::hasColumn('tasks', $column));
        }
        $foreignKey = DB::selectOne(<<<'SQL'
            SELECT kcu.REFERENCED_TABLE_NAME, rc.DELETE_RULE, rc.UPDATE_RULE
            FROM information_schema.KEY_COLUMN_USAGE kcu
            JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
              ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
             AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
             AND rc.TABLE_NAME = kcu.TABLE_NAME
            WHERE kcu.TABLE_SCHEMA = DATABASE()
              AND kcu.TABLE_NAME = 'tasks'
              AND kcu.COLUMN_NAME = 'branch_id'
              AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
            SQL);
        $this->assertSame('branches', $foreignKey->REFERENCED_TABLE_NAME);
        $this->assertSame('RESTRICT', $foreignKey->DELETE_RULE);
        $this->assertSame('RESTRICT', $foreignKey->UPDATE_RULE);
        $indexColumns = collect(DB::select(<<<'SQL'
            SELECT COLUMN_NAME
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'tasks'
              AND INDEX_NAME = 'tasks_branch_status_schedule_idx'
            ORDER BY SEQ_IN_INDEX
            SQL))->pluck('COLUMN_NAME')->all();
        $this->assertSame(['branch_id', 'status', 'scheduled_starts_at', 'scheduled_ends_at'], $indexColumns);
        $this->assertSame(['low', 'normal', 'high', 'urgent'], Task::priorities());
        $this->assertSame(['pending', 'in_progress', 'completed', 'cancelled'], Task::statuses());
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $user = User::factory()->create(['role' => User::ROLE_USER]);
        $this->assertTrue($admin->hasPermission('tasks.cancel'));
        $this->assertFalse($user->hasPermission('tasks.view'));
        $this->assertSame(['notifications.view', 'notifications.update', 'business_overview.view'], $user->permissions());
        $this->assertContains(CrmNotification::TYPE_TASK_ASSIGNED, CrmNotification::types());
    }

    public function test_admin_crud_auth_filters_and_contract(): void
    {
        $employee = $this->employeeWithSchedule();
        $payload = $this->taskData($employee);
        $this->postJson('/api/admin/tasks', $payload)->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_USER]));
        $this->getJson('/api/admin/tasks')->assertForbidden();
        $this->postJson('/api/admin/tasks', $payload)->assertForbidden();
        $admin = $this->actingAsAdmin();
        $id = $this->postJson('/api/admin/tasks', $payload)->assertCreated()
            ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.priority', 'high')
            ->assertJsonPath('data.created_by', $admin->id)->json('data.id');
        $this->getJson('/api/admin/tasks?status=pending&priority=high&search=Instalar')->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson("/api/admin/tasks/{$id}")->assertOk()->assertJsonPath('data.employee.id', $employee->id)->assertJsonPath('data.branch.id', $employee->branch_id);
        $this->patchJson("/api/admin/tasks/{$id}", ['title' => 'Instalar equipo actualizado'])->assertOk()->assertJsonPath('data.title', 'Instalar equipo actualizado');
        $this->assertDatabaseHas('tasks', ['id' => $id, 'updated_by' => $admin->id, 'branch_id' => $employee->branch_id]);
    }

    public function test_validation_timezone_pair_same_day_and_deadline_does_not_block(): void
    {
        $this->actingAsAdmin();
        $employee = $this->employeeWithSchedule();
        $this->postJson('/api/admin/tasks', [...$this->taskData($employee), 'scheduled_ends_at' => null])
            ->assertUnprocessable()->assertJsonValidationErrors('scheduled_ends_at');
        $this->postJson('/api/admin/tasks', [...$this->taskData($employee), 'scheduled_starts_at' => '2026-08-24T23:30:00-05:00', 'scheduled_ends_at' => '2026-08-25T00:30:00-05:00'])
            ->assertUnprocessable()->assertJsonValidationErrors('scheduled_ends_at');
        $id = $this->postJson('/api/admin/tasks', [...$this->taskData($employee), 'due_at' => '2020-01-01T08:00:00-05:00'])
            ->assertCreated()->json('data.id');
        $stored = Task::findOrFail($id);
        $this->assertSame('2026-08-24 14:00:00', $stored->getRawOriginal('scheduled_starts_at'));
        $this->assertSame('2020-01-01 13:00:00', $stored->getRawOriginal('due_at'));
    }

    public function test_schedule_leave_overlap_inactive_and_adjacency_rules(): void
    {
        $this->actingAsAdmin();
        $employee = $this->employeeWithSchedule();
        $first = $this->postJson('/api/admin/tasks', $this->taskData($employee))->assertCreated()->json('data.id');
        $this->postJson('/api/admin/tasks', [...$this->taskData($employee), 'scheduled_starts_at' => '2026-08-24T09:30:00-05:00', 'scheduled_ends_at' => '2026-08-24T10:30:00-05:00'])
            ->assertUnprocessable()->assertJsonValidationErrors('scheduled_starts_at');
        $this->postJson('/api/admin/tasks', [...$this->taskData($employee), 'scheduled_starts_at' => '2026-08-24T10:00:00-05:00', 'scheduled_ends_at' => '2026-08-24T11:00:00-05:00'])->assertCreated();
        $this->patchJson("/api/admin/tasks/{$first}", ['title' => 'Cambio no temporal'])->assertOk();
        $employee->update(['is_active' => false]);
        $this->postJson('/api/admin/tasks', [...$this->taskData($employee), 'scheduled_starts_at' => null, 'scheduled_ends_at' => null])
            ->assertUnprocessable()->assertJsonValidationErrors('assigned_employee_id');
    }

    public function test_only_outside_schedule_can_be_overridden_and_is_audited(): void
    {
        $admin = $this->actingAsAdmin();
        $employee = $this->employeeWithSchedule();
        $outside = [...$this->taskData($employee), 'scheduled_starts_at' => '2026-08-24T13:00:00-05:00', 'scheduled_ends_at' => '2026-08-24T14:00:00-05:00'];
        $this->postJson('/api/admin/tasks', $outside)->assertUnprocessable();
        $id = $this->postJson('/api/admin/tasks', [...$outside, 'availability_override' => true, 'availability_override_reason' => 'Atención excepcional autorizada'])
            ->assertCreated()->assertJsonPath('data.availability_override', true)->json('data.id');
        $this->assertDatabaseHas('tasks', ['id' => $id, 'availability_overridden_by' => $admin->id, 'availability_override_reason' => 'Atención excepcional autorizada']);
        $this->postJson('/api/admin/tasks', [...$outside, 'title' => 'Solapada', 'availability_override' => true, 'availability_override_reason' => 'No debe permitir'])
            ->assertUnprocessable()->assertJsonValidationErrors('scheduled_starts_at');
    }

    public function test_branch_snapshot_create_spoofing_and_inactive_branch_rules(): void
    {
        $this->actingAsAdmin();
        $baq = $this->branch('BAQ');
        $bog = $this->branch('BOG');
        $employee = $this->employeeWithSchedule(null, 'Técnico BAQ', $baq);

        $this->postJson('/api/admin/tasks', [...$this->taskData($employee), 'branch_id' => $bog->id])
            ->assertUnprocessable()->assertJsonValidationErrors('branch_id');

        $id = $this->postJson('/api/admin/tasks', [...$this->taskData($employee), 'scheduled_starts_at' => null, 'scheduled_ends_at' => null])
            ->assertCreated()
            ->assertJsonPath('data.branch_id', $baq->id)
            ->assertJsonPath('data.branch.id', $baq->id)
            ->assertJsonPath('data.branch.code', 'BAQ')
            ->json('data.id');
        $this->assertSame($baq->id, Task::findOrFail($id)->branch_id);
        $this->assertTrue($baq->tasks()->whereKey($id)->exists());

        $withoutBranch = Employee::create(['name' => 'Sin sede', 'job_title' => 'Técnico', 'is_active' => true]);
        $this->postJson('/api/admin/tasks', ['assigned_employee_id' => $withoutBranch->id, 'title' => 'Sin sede'])
            ->assertUnprocessable()->assertJsonValidationErrors('assigned_employee_id');

        $inactive = $this->branch('INA');
        $inactive->update(['is_active' => false]);
        $inactiveEmployee = Employee::create(['branch_id' => $inactive->id, 'name' => 'Sede inactiva', 'job_title' => 'Técnico', 'is_active' => true]);
        $this->postJson('/api/admin/tasks', ['assigned_employee_id' => $inactiveEmployee->id, 'title' => 'Sede inactiva'])
            ->assertUnprocessable()->assertJsonValidationErrors('assigned_employee_id');
    }

    public function test_branch_snapshot_update_reassignment_and_legacy_null_rules(): void
    {
        $this->actingAsAdmin();
        $baq = $this->branch('BAQ');
        $bog = $this->branch('BOG');
        $employee = $this->employeeWithSchedule(null, 'Técnico BAQ', $baq);
        $sameBranch = $this->employeeWithSchedule(null, 'Otro BAQ', $baq);
        $otherBranch = $this->employeeWithSchedule(null, 'Técnico BOG', $bog);
        $id = $this->postJson('/api/admin/tasks', $this->taskData($employee))->assertCreated()->json('data.id');

        $employee->update(['branch_id' => $bog->id]);
        $this->patchJson("/api/admin/tasks/{$id}", ['title' => 'No mueve sede'])
            ->assertOk()->assertJsonPath('data.branch_id', $baq->id)->assertJsonPath('data.branch.id', $baq->id);

        $this->patchJson("/api/admin/tasks/{$id}", ['assigned_employee_id' => $sameBranch->id, 'scheduled_starts_at' => '2026-08-24T10:00:00-05:00', 'scheduled_ends_at' => '2026-08-24T11:00:00-05:00'])
            ->assertOk()->assertJsonPath('data.branch_id', $baq->id);
        $this->patchJson("/api/admin/tasks/{$id}", ['assigned_employee_id' => $otherBranch->id])
            ->assertUnprocessable()->assertJsonValidationErrors('assigned_employee_id');

        $legacy = Task::create(['assigned_employee_id' => $employee->id, 'branch_id' => null, 'title' => 'Legacy', 'priority' => 'normal', 'status' => 'pending']);
        $this->patchJson("/api/admin/tasks/{$legacy->id}", ['assigned_employee_id' => $otherBranch->id])
            ->assertOk()->assertJsonPath('data.branch_id', $bog->id)->assertJsonPath('data.branch.id', $bog->id);
    }

    public function test_branch_filter_uses_task_snapshot_and_excludes_legacy_null(): void
    {
        $this->actingAsAdmin();
        $baq = $this->branch('BAQ');
        $bog = $this->branch('BOG');
        $employeeBaq = $this->employeeWithSchedule(null, 'BAQ', $baq);
        $employeeBog = $this->employeeWithSchedule(null, 'BOG', $bog);
        $baqTask = $this->postJson('/api/admin/tasks', $this->taskData($employeeBaq))->assertCreated()->json('data.id');
        $this->postJson('/api/admin/tasks', [...$this->taskData($employeeBog), 'scheduled_starts_at' => '2026-08-24T10:00:00-05:00', 'scheduled_ends_at' => '2026-08-24T11:00:00-05:00'])->assertCreated();
        Task::create(['assigned_employee_id' => $employeeBaq->id, 'branch_id' => null, 'title' => 'Legacy', 'priority' => 'normal', 'status' => 'pending']);

        $this->getJson("/api/admin/tasks?branch_id={$baq->id}")
            ->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.id', $baqTask);
        $this->getJson("/api/admin/tasks?branch_id={$bog->id}")
            ->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson('/api/admin/tasks?branch_id=999999')->assertUnprocessable()->assertJsonValidationErrors('branch_id');
    }

    public function test_transitions_terminal_edit_and_cancel_audit(): void
    {
        $admin = $this->actingAsAdmin();
        $employee = $this->employeeWithSchedule();
        $id = $this->postJson('/api/admin/tasks', $this->taskData($employee))->assertCreated()->json('data.id');
        $this->postJson("/api/admin/tasks/{$id}/start")->assertOk()->assertJsonPath('data.status', 'in_progress');
        $this->postJson("/api/admin/tasks/{$id}/complete")->assertOk()->assertJsonPath('data.status', 'completed');
        $this->patchJson("/api/admin/tasks/{$id}", ['title' => 'No'])->assertUnprocessable();
        $this->postJson("/api/admin/tasks/{$id}/cancel", ['reason' => 'No'])->assertUnprocessable();
        $other = $this->postJson('/api/admin/tasks', [...$this->taskData($employee), 'scheduled_starts_at' => '2026-08-24T10:00:00-05:00', 'scheduled_ends_at' => '2026-08-24T11:00:00-05:00'])->assertCreated()->json('data.id');
        $this->postJson("/api/admin/tasks/{$other}/cancel", ['reason' => 'Trabajo ya no requerido'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertDatabaseHas('tasks', ['id' => $other, 'cancelled_by' => $admin->id, 'cancellation_reason' => 'Trabajo ya no requerido']);
    }

    public function test_my_tasks_are_owned_and_work_without_global_permissions(): void
    {
        $userA = User::factory()->create(['role' => User::ROLE_USER, 'is_active' => true]);
        $userB = User::factory()->create(['role' => User::ROLE_USER, 'is_active' => true]);
        $employeeA = $this->employeeWithSchedule($userA);
        $employeeB = $this->employeeWithSchedule($userB, 'Otro empleado');
        $admin = $this->actingAsAdmin();
        $taskA = $this->postJson('/api/admin/tasks', $this->taskData($employeeA))->assertCreated()->json('data.id');
        $taskB = $this->postJson('/api/admin/tasks', [...$this->taskData($employeeB), 'scheduled_starts_at' => '2026-08-24T10:00:00-05:00', 'scheduled_ends_at' => '2026-08-24T11:00:00-05:00'])->assertCreated()->json('data.id');
        Sanctum::actingAs($userA);
        $this->getJson('/api/my/tasks')->assertOk()->assertJsonPath('data.employee_linked', true)->assertJsonPath('data.tasks.total', 1);
        $this->getJson("/api/my/tasks/{$taskB}")->assertNotFound();
        $this->postJson("/api/my/tasks/{$taskA}/start")->assertOk()->assertJsonPath('data.status', 'in_progress');
        $this->postJson("/api/my/tasks/{$taskA}/complete")->assertOk()->assertJsonPath('data.status', 'completed');
        $unlinked = User::factory()->create(['role' => User::ROLE_USER]);
        Sanctum::actingAs($unlinked);
        $this->getJson('/api/my/tasks')->assertOk()->assertJsonPath('data.employee_linked', false)->assertJsonCount(0, 'data.tasks');
        $this->getJson("/api/my/tasks/{$taskA}")->assertNotFound();
        $this->assertTrue($admin->hasPermission('tasks.update'));
    }

    public function test_task_notifications_are_personal_minimal_and_cover_reschedule_reassign_cancel(): void
    {
        $userA = User::factory()->create(['role' => User::ROLE_USER, 'is_active' => true]);
        $userB = User::factory()->create(['role' => User::ROLE_USER, 'is_active' => true]);
        $employeeA = $this->employeeWithSchedule($userA);
        $employeeB = $this->employeeWithSchedule($userB, 'Receptor nuevo');
        $this->actingAsAdmin();
        $id = $this->postJson('/api/admin/tasks', $this->taskData($employeeA))->assertCreated()->json('data.id');
        $this->assertDatabaseHas('crm_notifications', ['user_id' => $userA->id, 'type' => 'task_assigned', 'reference_type' => 'task', 'reference_id' => $id]);
        $this->patchJson("/api/admin/tasks/{$id}", ['scheduled_starts_at' => '2026-08-24T10:00:00-05:00', 'scheduled_ends_at' => '2026-08-24T11:00:00-05:00'])->assertOk();
        $this->patchJson("/api/admin/tasks/{$id}", ['assigned_employee_id' => $employeeB->id])->assertOk();
        $this->postJson("/api/admin/tasks/{$id}/cancel", ['reason' => 'Cancelada'])->assertOk();
        $this->assertDatabaseHas('crm_notifications', ['user_id' => $userB->id, 'type' => 'task_reassigned']);
        $this->assertDatabaseHas('crm_notifications', ['user_id' => $userB->id, 'type' => 'task_cancelled']);
        $notification = CrmNotification::where('type', 'task_assigned')->firstOrFail();
        $this->assertSame(['task_id', 'title', 'due_at', 'scheduled_starts_at', 'scheduled_ends_at'], array_keys($notification->data));
        $this->assertStringNotContainsString('email', json_encode($notification->data));
    }

    public function test_completed_and_cancelled_tasks_release_availability(): void
    {
        $this->actingAsAdmin();
        $employee = $this->employeeWithSchedule();
        $id = $this->postJson('/api/admin/tasks', $this->taskData($employee))->assertCreated()->json('data.id');
        $this->getJson('/api/admin/employees/availability?'.http_build_query(['starts_at' => '2026-08-24T09:30:00-05:00', 'ends_at' => '2026-08-24T09:45:00-05:00', 'employee_ids' => [$employee->id]]))
            ->assertJsonPath('data.0.reason_codes.0', 'task_overlap');
        $this->postJson("/api/admin/tasks/{$id}/complete")->assertOk();
        $this->getJson('/api/admin/employees/availability?'.http_build_query(['starts_at' => '2026-08-24T09:30:00-05:00', 'ends_at' => '2026-08-24T09:45:00-05:00', 'employee_ids' => [$employee->id]]))
            ->assertJsonPath('data.0.available', true);
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function employeeWithSchedule(?User $user = null, string $name = 'Técnico asignado', ?Branch $branch = null): Employee
    {
        $employee = Employee::create(['user_id' => $user?->id, 'branch_id' => ($branch ?? $this->branch())->id, 'name' => $name, 'job_title' => 'Técnico', 'is_active' => true]);
        EmployeeWorkSchedule::create(['employee_id' => $employee->id, 'day_of_week' => 1, 'starts_at' => '08:00', 'ends_at' => '12:00', 'effective_from' => '2026-01-01']);

        return $employee;
    }

    private function branch(string $code = 'BAQ'): Branch
    {
        return Branch::firstOrCreate(
            ['code' => $code],
            ['slug' => strtolower($code), 'name' => "Sede {$code}", 'city' => "Ciudad {$code}", 'is_active' => true],
        );
    }

    private function taskData(Employee $employee): array
    {
        return ['assigned_employee_id' => $employee->id, 'title' => 'Instalar equipo', 'description' => 'Trabajo técnico', 'priority' => 'high',
            'due_at' => '2026-08-24T17:00:00-05:00', 'scheduled_starts_at' => '2026-08-24T09:00:00-05:00', 'scheduled_ends_at' => '2026-08-24T10:00:00-05:00'];
    }
}
