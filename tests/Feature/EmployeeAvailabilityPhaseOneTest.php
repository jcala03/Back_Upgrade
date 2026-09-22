<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\EmployeeScheduleOverride;
use App\Models\EmployeeWorkSchedule;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeAvailabilityPhaseOneTest extends TestCase
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

    public function test_schema_models_relations_constants_and_permissions_are_ready(): void
    {
        foreach (['employee_work_schedules', 'employee_schedule_overrides', 'employee_leaves'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
        $employee = Employee::create($this->employeeData());
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $schedule = EmployeeWorkSchedule::create([...$this->scheduleData($employee), 'created_by' => $admin->id]);
        EmployeeScheduleOverride::create(['employee_id' => $employee->id, 'date' => '2026-08-25', 'type' => 'non_working']);
        EmployeeLeave::create(['employee_id' => $employee->id, 'type' => 'vacation', 'starts_at' => '2026-08-26 13:00:00', 'ends_at' => '2026-08-26 14:00:00', 'status' => 'approved']);

        $this->assertTrue($employee->workSchedules()->whereKey($schedule->id)->exists());
        $this->assertSame(1, $employee->scheduleOverrides()->count());
        $this->assertSame(1, $employee->leaves()->count());
        $this->assertSame(['working', 'non_working'], EmployeeScheduleOverride::types());
        $this->assertSame(['approved', 'cancelled'], EmployeeLeave::statuses());
        $this->assertTrue($admin->hasPermission('employee_availability.view'));
        $this->assertSame(['notifications.view', 'notifications.update', 'business_overview.view'], User::factory()->make(['role' => User::ROLE_USER])->permissions());
    }

    public function test_schedule_endpoints_require_auth_and_admin_permissions(): void
    {
        $employee = Employee::create($this->employeeData());
        $this->getJson('/api/admin/employee-schedules')->assertUnauthorized();
        $this->postJson('/api/admin/employee-schedules', $this->scheduleData($employee))->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_USER]));
        $this->getJson('/api/admin/employee-schedules')->assertForbidden();
        $this->postJson('/api/admin/employee-schedules', $this->scheduleData($employee))->assertForbidden();
        $this->getJson('/api/admin/employees/availability?starts_at=2026-08-24T09:00:00-05:00&ends_at=2026-08-24T10:00:00-05:00')->assertForbidden();
    }

    public function test_admin_crud_schedule_supports_split_days_and_physical_configuration_delete(): void
    {
        $admin = $this->actingAsAdmin();
        $employee = Employee::create($this->employeeData());
        $morning = $this->postJson('/api/admin/employee-schedules', $this->scheduleData($employee))
            ->assertCreated()->assertJsonPath('data.created_by', $admin->id)->json('data.id');
        $afternoon = $this->postJson('/api/admin/employee-schedules', [...$this->scheduleData($employee), 'starts_at' => '13:00', 'ends_at' => '17:00'])
            ->assertCreated()->json('data.id');
        $this->getJson("/api/admin/employee-schedules?employee_id={$employee->id}&day_of_week=1&effective_on=2026-08-24")
            ->assertOk()->assertJsonPath('data.total', 2);
        $this->patchJson("/api/admin/employee-schedules/{$afternoon}", ['ends_at' => '18:00'])->assertOk()->assertJsonPath('data.ends_at', '18:00:00');
        $this->deleteJson("/api/admin/employee-schedules/{$morning}")->assertOk();
        $this->assertDatabaseMissing('employee_work_schedules', ['id' => $morning]);
    }

    public function test_schedule_validates_hours_days_vigency_overlap_and_allows_adjacency(): void
    {
        $this->actingAsAdmin();
        $employee = Employee::create($this->employeeData());
        $this->postJson('/api/admin/employee-schedules', $this->scheduleData($employee))->assertCreated();
        $this->postJson('/api/admin/employee-schedules', [...$this->scheduleData($employee), 'starts_at' => '11:00', 'ends_at' => '13:00'])
            ->assertUnprocessable()->assertJsonValidationErrors('starts_at');
        $this->postJson('/api/admin/employee-schedules', [...$this->scheduleData($employee), 'starts_at' => '12:00', 'ends_at' => '13:00'])->assertCreated();
        $this->postJson('/api/admin/employee-schedules', [...$this->scheduleData($employee), 'starts_at' => '18:00', 'ends_at' => '08:00'])
            ->assertUnprocessable()->assertJsonValidationErrors('ends_at');
        $this->postJson('/api/admin/employee-schedules', [...$this->scheduleData($employee), 'day_of_week' => 8])
            ->assertUnprocessable()->assertJsonValidationErrors('day_of_week');
        $this->postJson('/api/admin/employee-schedules', [...$this->scheduleData($employee), 'effective_from' => '2026-09-01', 'effective_until' => '2026-08-31'])
            ->assertUnprocessable()->assertJsonValidationErrors('effective_until');
        $this->postJson('/api/admin/employee-schedules', [...$this->scheduleData($employee), 'effective_from' => '2027-01-01', 'effective_until' => null])->assertCreated();
    }

    public function test_overrides_validate_contract_uniqueness_crud_and_precedence(): void
    {
        $this->actingAsAdmin();
        $employee = Employee::create($this->employeeData());
        $this->postJson('/api/admin/employee-schedules', $this->scheduleData($employee))->assertCreated();
        $working = $this->postJson('/api/admin/employee-schedule-overrides', ['employee_id' => $employee->id, 'date' => '2026-08-29', 'type' => 'working', 'starts_at' => '10:00', 'ends_at' => '18:00'])
            ->assertCreated()->json('data.id');
        $this->availability($employee, '2026-08-29T10:00:00-05:00', '2026-08-29T11:00:00-05:00')->assertJsonPath('data.0.available', true);
        $this->postJson('/api/admin/employee-schedule-overrides', ['employee_id' => $employee->id, 'date' => '2026-08-30', 'type' => 'working'])
            ->assertUnprocessable()->assertJsonValidationErrors('starts_at');
        $this->postJson('/api/admin/employee-schedule-overrides', ['employee_id' => $employee->id, 'date' => '2026-08-30', 'type' => 'non_working', 'starts_at' => '08:00'])
            ->assertUnprocessable()->assertJsonValidationErrors('starts_at');
        $this->postJson('/api/admin/employee-schedule-overrides', ['employee_id' => $employee->id, 'date' => '2026-08-29', 'type' => 'non_working'])
            ->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->patchJson("/api/admin/employee-schedule-overrides/{$working}", ['type' => 'non_working', 'starts_at' => null, 'ends_at' => null])->assertOk();
        $this->availability($employee, '2026-08-29T10:00:00-05:00', '2026-08-29T11:00:00-05:00')
            ->assertJsonPath('data.0.available', false)->assertJsonPath('data.0.reason_codes.0', 'outside_work_schedule');
        $this->deleteJson("/api/admin/employee-schedule-overrides/{$working}")->assertOk();
    }

    public function test_leave_creation_is_approved_audited_supports_ranges_and_rejects_overlap(): void
    {
        $admin = $this->actingAsAdmin();
        $employee = Employee::create($this->employeeData());
        $leave = $this->postJson('/api/admin/employee-leaves', $this->leaveData($employee))
            ->assertCreated()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.approved_by', $admin->id)
            ->assertJsonPath('data.cancelled_at', null)->json('data.id');
        $this->assertNotNull(EmployeeLeave::findOrFail($leave)->approved_at);
        $this->postJson('/api/admin/employee-leaves', [...$this->leaveData($employee), 'starts_at' => '2026-08-24T10:00:00-05:00', 'ends_at' => '2026-08-24T12:00:00-05:00'])
            ->assertUnprocessable()->assertJsonValidationErrors('starts_at');
        $this->postJson('/api/admin/employee-leaves', [...$this->leaveData($employee), 'starts_at' => '2026-08-24T11:00:00-05:00', 'ends_at' => '2026-08-24T12:00:00-05:00'])->assertCreated();
        $this->postJson('/api/admin/employee-leaves', [...$this->leaveData($employee), 'starts_at' => '2026-08-25T00:00:00-05:00', 'ends_at' => '2026-08-28T00:00:00-05:00'])->assertCreated();
        $this->patchJson("/api/admin/employee-leaves/{$leave}", ['reason' => 'Razón actualizada'])->assertOk()->assertJsonPath('data.reason', 'Razón actualizada');
    }

    public function test_leave_cancel_is_idempotent_preserves_row_and_cancelled_leave_does_not_block(): void
    {
        $admin = $this->actingAsAdmin();
        $employee = Employee::create($this->employeeData());
        $this->postJson('/api/admin/employee-schedules', $this->scheduleData($employee))->assertCreated();
        $leave = $this->postJson('/api/admin/employee-leaves', $this->leaveData($employee))->assertCreated()->json('data.id');
        $this->availability($employee, '2026-08-24T09:30:00-05:00', '2026-08-24T10:00:00-05:00')
            ->assertJsonPath('data.0.available', false)->assertJsonPath('data.0.reason_codes.0', 'approved_leave');
        $this->postJson("/api/admin/employee-leaves/{$leave}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.cancelled_by', $admin->id);
        $cancelledAt = EmployeeLeave::findOrFail($leave)->cancelled_at;
        $this->postJson("/api/admin/employee-leaves/{$leave}/cancel")->assertOk();
        $this->assertTrue($cancelledAt->equalTo(EmployeeLeave::findOrFail($leave)->cancelled_at));
        $this->availability($employee, '2026-08-24T09:30:00-05:00', '2026-08-24T10:00:00-05:00')->assertJsonPath('data.0.available', true);
        $this->assertDatabaseHas('employee_leaves', ['id' => $leave, 'status' => 'cancelled']);
    }

    public function test_availability_handles_active_state_missing_schedule_split_day_and_outside_ranges(): void
    {
        $this->actingAsAdmin();
        $employee = Employee::create($this->employeeData());
        $this->availability($employee, '2026-08-24T09:00:00-05:00', '2026-08-24T10:00:00-05:00')
            ->assertJsonPath('data.0.reason_codes.0', 'no_work_schedule');
        $this->postJson('/api/admin/employee-schedules', $this->scheduleData($employee))->assertCreated();
        $this->postJson('/api/admin/employee-schedules', [...$this->scheduleData($employee), 'starts_at' => '13:00', 'ends_at' => '17:00'])->assertCreated();
        $this->availability($employee, '2026-08-24T09:00:00-05:00', '2026-08-24T10:00:00-05:00')->assertJsonPath('data.0.available', true);
        $this->availability($employee, '2026-08-24T11:30:00-05:00', '2026-08-24T13:30:00-05:00')
            ->assertJsonPath('data.0.available', false)->assertJsonPath('data.0.reason_codes.0', 'outside_work_schedule');
        $employee->update(['is_active' => false]);
        $this->availability($employee, '2026-08-24T09:00:00-05:00', '2026-08-24T10:00:00-05:00')
            ->assertJsonPath('data.0.reason_codes.0', 'inactive_employee');
    }

    public function test_availability_rejects_invalid_and_multiday_ranges_in_bogota(): void
    {
        $this->actingAsAdmin();
        $employee = Employee::create($this->employeeData());
        $this->availability($employee, '2026-08-24T10:00:00-05:00', '2026-08-24T09:00:00-05:00')->assertUnprocessable()->assertJsonValidationErrors('ends_at');
        $this->availability($employee, '2026-08-24T23:30:00-05:00', '2026-08-25T00:30:00-05:00')->assertUnprocessable()->assertJsonValidationErrors('ends_at');
    }

    public function test_timezone_is_bogota_leaves_use_utc_and_availability_response_is_private(): void
    {
        $this->actingAsAdmin();
        $employee = Employee::create($this->employeeData());
        $this->postJson('/api/admin/employee-schedules', $this->scheduleData($employee))->assertCreated();
        $leave = $this->postJson('/api/admin/employee-leaves', [...$this->leaveData($employee), 'reason' => 'Consulta médica privada', 'notes' => 'Diagnóstico reservado'])->assertCreated()->json('data.id');
        $stored = EmployeeLeave::findOrFail($leave);
        $this->assertSame('2026-08-24 14:00:00', $stored->getRawOriginal('starts_at'));
        $response = $this->availability($employee, '2026-08-24T09:30:00-05:00', '2026-08-24T10:00:00-05:00')
            ->assertOk()->assertJsonPath('data.0.reason_codes.0', 'approved_leave')
            ->assertJsonMissingPath('data.0.employee.email');
        $payload = $response->getContent();
        $this->assertStringNotContainsString('Consulta médica privada', $payload);
        $this->assertStringNotContainsString('Diagnóstico reservado', $payload);
        $this->assertStringNotContainsString('password', $payload);
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function employeeData(): array
    {
        return ['name' => 'Carlos Operativo', 'job_title' => 'Pintor', 'specialty' => 'Pintura automotriz', 'is_active' => true];
    }

    private function scheduleData(Employee $employee): array
    {
        return ['employee_id' => $employee->id, 'day_of_week' => 1, 'starts_at' => '08:00', 'ends_at' => '12:00', 'effective_from' => '2026-01-01', 'effective_until' => '2026-12-31'];
    }

    private function leaveData(Employee $employee): array
    {
        return ['employee_id' => $employee->id, 'type' => 'permission', 'starts_at' => '2026-08-24T09:00:00-05:00', 'ends_at' => '2026-08-24T11:00:00-05:00', 'reason' => 'Permiso', 'notes' => 'Privado'];
    }

    private function availability(Employee $employee, string $startsAt, string $endsAt)
    {
        return $this->getJson('/api/admin/employees/availability?'.http_build_query(['starts_at' => $startsAt, 'ends_at' => $endsAt, 'employee_ids' => [$employee->id]]));
    }
}
