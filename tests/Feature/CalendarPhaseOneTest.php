<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\CrmNotification;
use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\EmployeeScheduleOverride;
use App\Models\EmployeeWorkSchedule;
use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CalendarPhaseOneTest extends TestCase
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

    public function test_authorization_permissions_and_required_bounded_range(): void
    {
        $url = $this->url('2026-08-01T00:00:00-05:00', '2026-08-31T00:00:00-05:00');
        $this->getJson($url)->assertUnauthorized();
        $user = User::factory()->create(['role' => User::ROLE_USER]);
        Sanctum::actingAs($user);
        $this->getJson($url)->assertForbidden();
        $this->assertFalse($user->hasPermission('calendar.view'));
        $admin = $this->admin();
        $this->assertTrue($admin->hasPermission('calendar.view'));
        $this->getJson('/api/admin/calendar')->assertUnprocessable()->assertJsonValidationErrors(['from', 'to']);
        $this->getJson($this->url('2026-08-02T00:00:00-05:00', '2026-08-01T00:00:00-05:00'))->assertUnprocessable();
        $this->getJson($this->url('2026-08-01T00:00:00-05:00', '2026-09-02T00:00:00-05:00'))->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->getJson($url)->assertOk()->assertJsonPath('meta.timezone', 'America/Bogota');
    }

    public function test_appointments_tasks_leaves_and_boundaries_are_aggregated_with_whitelisted_meta(): void
    {
        $this->admin();
        $employee = $this->employee();
        $this->appointment($employee, 'requested', '2026-08-24 14:00:00', '2026-08-24 15:00:00', 'Cita visible');
        $this->appointment($employee, 'cancelled', '2026-08-24 15:00:00', '2026-08-24 16:00:00', 'Cita cancelada');
        Task::create(['assigned_employee_id' => $employee->id, 'title' => 'Tarea programada', 'priority' => 'urgent', 'status' => 'completed',
            'due_at' => '2026-08-25 15:00:00', 'scheduled_starts_at' => '2026-08-24 16:00:00', 'scheduled_ends_at' => '2026-08-24 17:00:00']);
        Task::create(['assigned_employee_id' => $employee->id, 'title' => 'Solo deadline', 'priority' => 'normal', 'status' => 'pending', 'due_at' => '2026-08-24 18:00:00']);
        EmployeeLeave::create(['employee_id' => $employee->id, 'type' => 'permission', 'status' => 'approved', 'reason' => 'Dato sensible', 'notes' => 'Nota médica',
            'starts_at' => '2026-08-24 17:00:00', 'ends_at' => '2026-08-24 18:00:00']);
        EmployeeLeave::create(['employee_id' => $employee->id, 'type' => 'vacation', 'status' => 'cancelled',
            'starts_at' => '2026-08-24 18:00:00', 'ends_at' => '2026-08-24 19:00:00']);
        $response = $this->getJson($this->url('2026-08-24T09:00:00-05:00', '2026-08-24T13:00:00-05:00'))
            ->assertOk()->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.starts_at', '2026-08-24T09:00:00-05:00')
            ->assertJsonMissingPath('data.0.meta.contact_email')->assertJsonMissingPath('data.0.meta.contact_phone');
        $this->assertStringStartsWith('appointment:', $response->json('data.0.id'));
        $content = $response->getContent();
        $this->assertStringNotContainsString('Solo deadline', $content);
        $this->assertStringNotContainsString('Nota médica', $content);
        $this->assertStringNotContainsString('Dato sensible', $content);
        $this->assertStringNotContainsString('vacation', $content);
    }

    public function test_schedule_expansion_split_vigency_and_bogota_serialization(): void
    {
        $this->admin();
        $employee = $this->employee();
        EmployeeWorkSchedule::create(['employee_id' => $employee->id, 'day_of_week' => 1, 'starts_at' => '09:00', 'ends_at' => '12:00', 'effective_from' => '2026-08-01', 'effective_until' => '2026-08-31']);
        EmployeeWorkSchedule::create(['employee_id' => $employee->id, 'day_of_week' => 1, 'starts_at' => '13:00', 'ends_at' => '17:00', 'effective_from' => '2026-08-24', 'effective_until' => '2026-08-24']);
        EmployeeWorkSchedule::create(['employee_id' => $employee->id, 'day_of_week' => 2, 'starts_at' => '08:00', 'ends_at' => '09:00', 'effective_from' => '2027-01-01']);
        $response = $this->getJson($this->url('2026-08-24T00:00:00-05:00', '2026-08-25T00:00:00-05:00', ['types' => ['work_schedule']]))
            ->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.starts_at', '2026-08-24T09:00:00-05:00')->assertJsonPath('data.1.starts_at', '2026-08-24T13:00:00-05:00');
        $this->assertStringStartsWith('schedule:', $response->json('data.0.id'));
        $this->assertStringEndsWith(':2026-08-24', $response->json('data.0.id'));
        $this->assertNotSame($response->json('data.0.id'), $response->json('data.1.id'));
    }

    public function test_working_and_non_working_overrides_replace_only_their_dates(): void
    {
        $this->admin();
        $employee = $this->employee();
        EmployeeWorkSchedule::create(['employee_id' => $employee->id, 'day_of_week' => 1, 'starts_at' => '08:00', 'ends_at' => '12:00', 'effective_from' => '2026-01-01']);
        EmployeeScheduleOverride::create(['employee_id' => $employee->id, 'date' => '2026-08-24', 'type' => 'working', 'starts_at' => '10:00', 'ends_at' => '14:00', 'reason' => 'Privado']);
        EmployeeScheduleOverride::create(['employee_id' => $employee->id, 'date' => '2026-08-25', 'type' => 'non_working', 'reason' => 'Privado']);
        $response = $this->getJson($this->url('2026-08-24T00:00:00-05:00', '2026-08-26T00:00:00-05:00'))
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.title', 'Horario excepcional')
            ->assertJsonPath('data.1.title', 'No laborable')->assertJsonPath('data.1.all_day', true);
        $this->assertStringNotContainsString('Privado', $response->getContent());
        $this->assertStringNotContainsString('Horario laboral', $response->getContent());
    }

    public function test_ownership_inactive_employee_and_user_privacy_cover_every_source(): void
    {
        $userA = User::factory()->create(['role' => User::ROLE_USER]);
        $userB = User::factory()->create(['role' => User::ROLE_USER]);
        $employeeA = $this->employee($userA, 'Propio');
        $employeeB = $this->employee($userB, 'Ajeno');
        foreach ([[$employeeA, 'Propia'], [$employeeB, 'Ajena']] as [$employee, $suffix]) {
            EmployeeWorkSchedule::create(['employee_id' => $employee->id, 'day_of_week' => 1, 'starts_at' => '08:00', 'ends_at' => '09:00', 'effective_from' => '2026-01-01']);
            EmployeeLeave::create(['employee_id' => $employee->id, 'type' => 'absence', 'status' => 'approved', 'notes' => "Nota {$suffix}", 'starts_at' => '2026-08-24 14:00:00', 'ends_at' => '2026-08-24 15:00:00']);
            Task::create(['assigned_employee_id' => $employee->id, 'title' => "Tarea {$suffix}", 'priority' => 'normal', 'status' => 'pending', 'scheduled_starts_at' => '2026-08-24 15:00:00', 'scheduled_ends_at' => '2026-08-24 16:00:00']);
            $this->appointment($employee, 'confirmed', '2026-08-24 16:00:00', '2026-08-24 17:00:00', "Cita {$suffix}");
        }
        $employeeA->update(['is_active' => false]);
        Sanctum::actingAs($userA);
        $response = $this->getJson($this->myUrl())->assertOk()->assertJsonPath('meta.employee_linked', true)->assertJsonCount(4, 'data');
        $content = $response->getContent();
        $this->assertStringContainsString('Propia', $content);
        $this->assertStringNotContainsString('Ajena', $content);
        $this->assertStringNotContainsString('Nota Propia', $content);
        $this->assertStringNotContainsString('email', $content);
        $this->assertStringNotContainsString('permissions', $content);
        $this->assertStringNotContainsString('customer_id', $content);
    }

    public function test_user_without_employee_and_forbidden_employee_filter(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_USER]));
        $this->getJson($this->myUrl())->assertOk()->assertJsonPath('meta.employee_linked', false)->assertJsonCount(0, 'data');
        $this->getJson($this->myUrl(['employee_ids' => [1]]))->assertUnprocessable()->assertJsonValidationErrors('employee_ids');
    }

    public function test_type_employee_status_filters_and_deterministic_order(): void
    {
        $this->admin();
        $first = $this->employee(null, 'Primero');
        $second = $this->employee(null, 'Segundo');
        $this->appointment($first, 'requested', '2026-08-24 15:00:00', '2026-08-24 16:00:00', 'B Appointment');
        Task::create(['assigned_employee_id' => $first->id, 'title' => 'A Task', 'priority' => 'normal', 'status' => 'pending', 'scheduled_starts_at' => '2026-08-24 15:00:00', 'scheduled_ends_at' => '2026-08-24 16:00:00']);
        Task::create(['assigned_employee_id' => $second->id, 'title' => 'Otro', 'priority' => 'normal', 'status' => 'completed', 'scheduled_starts_at' => '2026-08-24 16:00:00', 'scheduled_ends_at' => '2026-08-24 17:00:00']);
        $this->getJson($this->url('2026-08-24T00:00:00-05:00', '2026-08-25T00:00:00-05:00', ['types' => ['appointment']]))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.type', 'appointment');
        $response = $this->getJson($this->url('2026-08-24T00:00:00-05:00', '2026-08-25T00:00:00-05:00',
            ['types' => ['task', 'appointment'], 'statuses' => ['pending', 'requested'], 'employee_ids' => [$first->id]]))
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.type', 'appointment')->assertJsonPath('data.1.type', 'task');
        $this->assertStringStartsWith('appointment:', $response->json('data.0.id'));
        $this->assertStringStartsWith('task:', $response->json('data.1.id'));
        $this->getJson($this->url('2026-08-24T00:00:00-05:00', '2026-08-25T00:00:00-05:00', ['types' => ['invalid']]))->assertUnprocessable();
        $this->getJson($this->url('2026-08-24T00:00:00-05:00', '2026-08-25T00:00:00-05:00', ['employee_ids' => [99999]]))->assertUnprocessable();
    }

    public function test_branch_filter_uses_operational_snapshots_for_appointments_and_tasks_only(): void
    {
        $this->admin();
        $baq = $this->branch('BAQ');
        $bog = $this->branch('BOG');
        $employee = $this->employee(null, 'Técnico', $baq);
        $this->appointment($employee, 'confirmed', '2026-08-24 14:00:00', '2026-08-24 15:00:00', 'Cita BAQ', $baq);
        $this->appointment($employee, 'confirmed', '2026-08-24 15:00:00', '2026-08-24 16:00:00', 'Cita BOG', $bog);
        Appointment::create(['responsible_employee_id' => $employee->id, 'branch_id' => null, 'source' => 'crm', 'status' => 'confirmed',
            'title' => 'Cita legacy', 'contact_name' => 'Cliente', 'contact_phone' => '3000000000',
            'starts_at' => '2026-08-24 16:00:00', 'ends_at' => '2026-08-24 17:00:00']);
        Task::create(['assigned_employee_id' => $employee->id, 'branch_id' => $baq->id, 'title' => 'Tarea BAQ', 'priority' => 'normal',
            'status' => 'pending', 'scheduled_starts_at' => '2026-08-24 17:00:00', 'scheduled_ends_at' => '2026-08-24 18:00:00']);
        Task::create(['assigned_employee_id' => $employee->id, 'branch_id' => $bog->id, 'title' => 'Tarea BOG', 'priority' => 'normal',
            'status' => 'pending', 'scheduled_starts_at' => '2026-08-24 18:00:00', 'scheduled_ends_at' => '2026-08-24 19:00:00']);
        Task::create(['assigned_employee_id' => $employee->id, 'branch_id' => null, 'title' => 'Tarea sin snapshot', 'priority' => 'normal',
            'status' => 'pending', 'scheduled_starts_at' => '2026-08-24 19:00:00', 'scheduled_ends_at' => '2026-08-24 20:00:00']);
        EmployeeLeave::create(['employee_id' => $employee->id, 'type' => 'permission', 'status' => 'approved',
            'starts_at' => '2026-08-24 20:00:00', 'ends_at' => '2026-08-24 21:00:00']);
        EmployeeWorkSchedule::create(['employee_id' => $employee->id, 'day_of_week' => 1, 'starts_at' => '08:00', 'ends_at' => '12:00', 'effective_from' => '2026-01-01']);
        EmployeeScheduleOverride::create(['employee_id' => $employee->id, 'date' => '2026-08-24', 'type' => 'working', 'starts_at' => '12:00', 'ends_at' => '13:00']);

        $baqResponse = $this->getJson($this->url('2026-08-24T00:00:00-05:00', '2026-08-25T00:00:00-05:00', ['branch_id' => $baq->id]))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.title', 'Cita BAQ')
            ->assertJsonPath('data.0.branch.id', $baq->id)
            ->assertJsonPath('data.0.branch.code', 'BAQ')
            ->assertJsonPath('data.1.title', 'Tarea BAQ')
            ->assertJsonPath('data.1.branch.id', $baq->id);
        $this->assertStringNotContainsString('Tarea sin snapshot', $baqResponse->getContent());
        $this->assertStringNotContainsString('permission', $baqResponse->getContent());
        $this->assertStringNotContainsString('Horario laboral', $baqResponse->getContent());
        $this->assertStringNotContainsString('Horario excepcional', $baqResponse->getContent());

        $this->getJson($this->url('2026-08-24T00:00:00-05:00', '2026-08-25T00:00:00-05:00', ['branch_id' => $bog->id]))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.title', 'Cita BOG')
            ->assertJsonPath('data.1.title', 'Tarea BOG');
        $this->getJson($this->url('2026-08-24T00:00:00-05:00', '2026-08-25T00:00:00-05:00', ['branch_id' => 99999]))
            ->assertUnprocessable()->assertJsonValidationErrors('branch_id');
    }

    public function test_calendar_is_read_only_and_has_no_notification_side_effects(): void
    {
        $this->admin();
        $employee = $this->employee();
        $task = Task::create(['assigned_employee_id' => $employee->id, 'title' => 'Inmutable', 'priority' => 'normal', 'status' => 'pending', 'scheduled_starts_at' => '2026-08-24 14:00:00', 'scheduled_ends_at' => '2026-08-24 15:00:00']);
        $appointment = $this->appointment($employee, 'requested', '2026-08-24 15:00:00', '2026-08-24 16:00:00', 'Inmutable');
        $taskUpdated = $task->updated_at;
        $appointmentUpdated = Appointment::findOrFail($appointment)->updated_at;
        $notifications = CrmNotification::count();
        $this->getJson($this->url('2026-08-24T00:00:00-05:00', '2026-08-25T00:00:00-05:00'))->assertOk();
        $this->assertTrue($taskUpdated->equalTo($task->fresh()->updated_at));
        $this->assertTrue($appointmentUpdated->equalTo(Appointment::findOrFail($appointment)->updated_at));
        $this->assertSame($notifications, CrmNotification::count());
    }

    private function admin(): User
    {
        $user = User::factory()->create(['role' => User::ROLE_ADMIN]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function employee(?User $user = null, string $name = 'Empleado', ?Branch $branch = null): Employee
    {
        return Employee::create(['user_id' => $user?->id, 'branch_id' => ($branch ?? $this->branch())->id,
            'name' => $name, 'job_title' => 'Técnico', 'is_active' => true]);
    }

    private function appointment(Employee $employee, string $status, string $start, string $end, string $title, ?Branch $branch = null): int
    {
        return Appointment::create(['responsible_employee_id' => $employee->id, 'branch_id' => $branch?->id ?? $employee->branch_id,
            'source' => 'crm', 'status' => $status, 'title' => $title,
            'contact_name' => 'Cliente', 'contact_phone' => '3000000000', 'contact_email' => 'private@example.com', 'vehicle_description' => 'Vehículo',
            'service_name' => 'Servicio', 'starts_at' => $start, 'ends_at' => $end])->id;
    }

    private function branch(string $code = 'BAQ'): Branch
    {
        return Branch::firstOrCreate(
            ['code' => $code],
            ['slug' => strtolower($code), 'name' => "Sede {$code}", 'city' => "Ciudad {$code}", 'is_active' => true],
        );
    }

    private function url(string $from, string $to, array $extra = []): string
    {
        return '/api/admin/calendar?'.http_build_query(['from' => $from, 'to' => $to, ...$extra]);
    }

    private function myUrl(array $extra = []): string
    {
        return '/api/my/calendar?'.http_build_query(['from' => '2026-08-24T00:00:00-05:00', 'to' => '2026-08-25T00:00:00-05:00', ...$extra]);
    }
}
