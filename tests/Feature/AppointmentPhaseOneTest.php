<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\CrmNotification;
use App\Models\Customer;
use App\Models\CustomerVehicle;
use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\EmployeeWorkSchedule;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Task;
use App\Models\User;
use App\Models\VehicleBrand;
use App\Models\VehicleModel;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AppointmentPhaseOneTest extends TestCase
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
        $this->assertTrue(Schema::hasTable('appointments'));
        foreach (['customer_id', 'responsible_employee_id', 'branch_id', 'starts_at', 'availability_override'] as $column) {
            $this->assertTrue(Schema::hasColumn('appointments', $column));
        }
        $this->assertSame(['crm', 'public_web'], Appointment::sources());
        $this->assertSame(['requested', 'confirmed', 'in_progress', 'completed', 'cancelled', 'no_show'], Appointment::statuses());
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $user = User::factory()->create(['role' => User::ROLE_USER]);
        $this->assertTrue($admin->hasPermission('appointments.cancel'));
        $this->assertFalse($user->hasPermission('appointments.view'));
        $this->assertContains('appointment_assigned', CrmNotification::types());
    }

    public function test_authorization_admin_crud_filters_and_ad_hoc_contact(): void
    {
        $payload = $this->payload();
        $this->postJson('/api/admin/appointments', $payload)->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_USER]));
        $this->getJson('/api/admin/appointments')->assertForbidden();
        $this->postJson('/api/admin/appointments', $payload)->assertForbidden();
        $admin = $this->admin();
        $id = $this->postJson('/api/admin/appointments', $payload)->assertCreated()->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.source', 'crm')->assertJsonPath('data.created_by', $admin->id)->json('data.id');
        $this->getJson('/api/admin/appointments?status=confirmed&search=Diagnóstico')->assertOk()->assertJsonPath('data.total', 1);
        $this->patchJson("/api/admin/appointments/{$id}", ['title' => 'Diagnóstico actualizado'])->assertOk();
        $this->patchJson("/api/admin/appointments/{$id}", ['starts_at' => '2026-08-24T10:00:00-05:00'])->assertUnprocessable();
        $this->assertSame(0, Customer::count());
    }

    public function test_customer_vehicle_and_service_snapshots_are_coherent_and_frozen(): void
    {
        $this->admin();
        $customer = Customer::create(['name' => 'Juan Cliente', 'phone' => '3001234567', 'email' => 'juan@example.com']);
        $other = Customer::create(['name' => 'Otro', 'phone' => '3010000000']);
        $brand = VehicleBrand::create(['name' => 'BMW', 'slug' => 'bmw', 'is_active' => true]);
        $model = VehicleModel::create(['vehicle_brand_id' => $brand->id, 'name' => 'X3', 'slug' => 'x3', 'is_active' => true]);
        $vehicle = CustomerVehicle::create(['customer_id' => $customer->id, 'vehicle_brand_id' => $brand->id, 'vehicle_model_id' => $model->id, 'year' => 2022, 'plate' => 'ABC123', 'is_active' => true]);
        $service = $this->service();
        $id = $this->postJson('/api/admin/appointments', [...$this->payload(), 'customer_id' => $customer->id,
            'customer_vehicle_id' => $vehicle->id, 'service_id' => $service->id, 'contact_name' => 'Falso', 'contact_phone' => '0'])
            ->assertCreated()->assertJsonPath('data.contact_name', 'Juan Cliente')->assertJsonPath('data.service_name', 'Instalación')->json('data.id');
        $this->assertStringContainsString('BMW', Appointment::findOrFail($id)->vehicle_description);
        $customer->update(['name' => 'Nombre nuevo']);
        $service->update(['name' => 'Servicio nuevo', 'is_active' => false]);
        $this->patchJson("/api/admin/appointments/{$id}", ['description' => 'Conserva histórico'])->assertOk()
            ->assertJsonPath('data.contact_name', 'Juan Cliente')->assertJsonPath('data.service_name', 'Instalación');
        $this->postJson('/api/admin/appointments', [...$this->payload(), 'customer_id' => $other->id, 'customer_vehicle_id' => $vehicle->id])
            ->assertUnprocessable()->assertJsonValidationErrors('customer_vehicle_id');
        $this->postJson('/api/admin/appointments', [...$this->payload(), 'customer_vehicle_id' => $vehicle->id])
            ->assertUnprocessable()->assertJsonValidationErrors('customer_vehicle_id');
        $this->postJson('/api/admin/appointments', [...$this->payload(), 'service_id' => $service->id])->assertUnprocessable()->assertJsonValidationErrors('service_id');
    }

    public function test_requested_does_not_block_but_confirmation_revalidates(): void
    {
        $this->admin();
        $employee = $this->employee();
        Task::create(['assigned_employee_id' => $employee->id, 'title' => 'Trabajo', 'status' => 'pending', 'priority' => 'normal',
            'scheduled_starts_at' => '2026-08-24 14:00:00', 'scheduled_ends_at' => '2026-08-24 16:00:00']);
        $id = $this->postJson('/api/admin/appointments', [...$this->payload(), 'status' => 'requested', 'responsible_employee_id' => $employee->id])
            ->assertCreated()->json('data.id');
        $this->assertDatabaseMissing('crm_notifications', ['reference_type' => 'appointment', 'reference_id' => $id]);
        $this->postJson("/api/admin/appointments/{$id}/status", ['status' => 'confirmed'])->assertUnprocessable()->assertJsonValidationErrors('starts_at');
        Task::query()->update(['status' => 'completed']);
        $this->postJson("/api/admin/appointments/{$id}/status", ['status' => 'confirmed'])->assertOk()->assertJsonPath('data.status', 'confirmed');
    }

    public function test_confirmed_availability_override_overlap_adjacency_and_timezone(): void
    {
        $admin = $this->admin();
        $employee = $this->employee();
        $id = $this->postJson('/api/admin/appointments', [...$this->payload(), 'responsible_employee_id' => $employee->id])
            ->assertCreated()->json('data.id');
        $this->assertSame('2026-08-24 14:00:00', Appointment::findOrFail($id)->getRawOriginal('starts_at'));
        $this->postJson('/api/admin/appointments', [...$this->payload(), 'responsible_employee_id' => $employee->id,
            'starts_at' => '2026-08-24T09:30:00-05:00', 'ends_at' => '2026-08-24T10:30:00-05:00'])->assertUnprocessable();
        $this->postJson('/api/admin/appointments', [...$this->payload(), 'responsible_employee_id' => $employee->id,
            'starts_at' => '2026-08-24T10:00:00-05:00', 'ends_at' => '2026-08-24T11:00:00-05:00'])->assertCreated();
        $outside = [...$this->payload(), 'responsible_employee_id' => $employee->id, 'starts_at' => '2026-08-24T13:00:00-05:00', 'ends_at' => '2026-08-24T14:00:00-05:00'];
        $override = $this->postJson('/api/admin/appointments', [...$outside, 'availability_override' => true, 'availability_override_reason' => 'Excepción aprobada'])
            ->assertCreated()->assertJsonPath('data.availability_override', true)->json('data.id');
        $this->assertDatabaseHas('appointments', ['id' => $override, 'availability_overridden_by' => $admin->id]);
        $this->postJson('/api/admin/appointments', [...$this->payload(), 'starts_at' => '2026-08-24T23:30:00-05:00', 'ends_at' => '2026-08-25T00:30:00-05:00'])
            ->assertUnprocessable()->assertJsonValidationErrors('ends_at');
    }

    public function test_task_and_appointment_cross_block_and_terminal_release(): void
    {
        $this->admin();
        $employee = $this->employee();
        $appointment = $this->postJson('/api/admin/appointments', [...$this->payload(), 'responsible_employee_id' => $employee->id])->assertCreated()->json('data.id');
        $this->postJson('/api/admin/tasks', ['assigned_employee_id' => $employee->id, 'title' => 'Tarea cruzada',
            'scheduled_starts_at' => '2026-08-24T09:30:00-05:00', 'scheduled_ends_at' => '2026-08-24T10:30:00-05:00'])
            ->assertUnprocessable()->assertJsonValidationErrors('scheduled_starts_at');
        $this->postJson("/api/admin/appointments/{$appointment}/status", ['status' => 'completed'])->assertOk();
        $this->postJson('/api/admin/tasks', ['assigned_employee_id' => $employee->id, 'title' => 'Tarea liberada',
            'scheduled_starts_at' => '2026-08-24T09:30:00-05:00', 'scheduled_ends_at' => '2026-08-24T10:30:00-05:00'])->assertCreated();
    }

    public function test_reschedule_reassign_states_cancel_and_notifications(): void
    {
        $userA = User::factory()->create(['role' => User::ROLE_USER, 'is_active' => true]);
        $userB = User::factory()->create(['role' => User::ROLE_USER, 'is_active' => true]);
        $employeeA = $this->employee($userA);
        $employeeB = $this->employee($userB, 'Técnico B');
        $this->admin();
        $id = $this->postJson('/api/admin/appointments', [...$this->payload(), 'responsible_employee_id' => $employeeA->id])->assertCreated()->json('data.id');
        $this->assertDatabaseHas('crm_notifications', ['user_id' => $userA->id, 'type' => 'appointment_assigned']);
        $this->postJson("/api/admin/appointments/{$id}/reschedule", ['starts_at' => '2026-08-24T10:00:00-05:00', 'ends_at' => '2026-08-24T11:00:00-05:00'])->assertOk();
        $this->patchJson("/api/admin/appointments/{$id}", ['responsible_employee_id' => $employeeB->id])->assertOk();
        $this->assertDatabaseHas('crm_notifications', ['user_id' => $userB->id, 'type' => 'appointment_assigned']);
        $this->postJson("/api/admin/appointments/{$id}/status", ['status' => 'in_progress'])->assertOk();
        $this->postJson("/api/admin/appointments/{$id}/reschedule", ['starts_at' => '2026-08-24T11:00:00-05:00', 'ends_at' => '2026-08-24T12:00:00-05:00'])->assertUnprocessable();
        $this->postJson("/api/admin/appointments/{$id}/cancel", ['cancellation_reason' => 'Cliente canceló'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertDatabaseHas('crm_notifications', ['user_id' => $userB->id, 'type' => 'appointment_cancelled']);
        $this->postJson("/api/admin/appointments/{$id}/status", ['status' => 'completed'])->assertUnprocessable();
    }

    public function test_employee_branch_is_snapshotted_and_branch_spoofing_is_rejected(): void
    {
        $this->admin();
        $branch = $this->branch('BAQ');
        $otherBranch = $this->branch('BOG');
        $employee = $this->employee(null, 'Técnico BAQ', $branch);

        $id = $this->postJson('/api/admin/appointments', [
            ...$this->payload(),
            'responsible_employee_id' => $employee->id,
            'branch_id' => $otherBranch->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('branch_id');

        $id = $this->postJson('/api/admin/appointments', [...$this->payload(), 'responsible_employee_id' => $employee->id])
            ->assertCreated()
            ->assertJsonPath('data.branch_id', $branch->id)
            ->assertJsonPath('data.branch.id', $branch->id)
            ->assertJsonPath('data.branch.code', 'BAQ')
            ->assertJsonPath('data.branch.name', 'Sede BAQ')
            ->json('data.id');

        $employee->update(['branch_id' => $otherBranch->id]);
        $this->patchJson("/api/admin/appointments/{$id}", ['title' => 'Edita sin mover sede'])
            ->assertOk()
            ->assertJsonPath('data.branch_id', $branch->id)
            ->assertJsonPath('data.branch.id', $branch->id);
        $this->getJson("/api/admin/appointments?branch_id={$branch->id}")
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.branch.id', $branch->id);
        $this->getJson("/api/admin/appointments?branch_id={$otherBranch->id}")
            ->assertOk()
            ->assertJsonPath('data.total', 0);
    }

    public function test_all_approved_state_transitions_and_terminal_rules(): void
    {
        $this->admin();
        $requestedConfirmed = $this->appointment('requested');
        $this->postJson("/api/admin/appointments/{$requestedConfirmed}/status", ['status' => 'confirmed'])->assertOk();
        $this->postJson("/api/admin/appointments/{$requestedConfirmed}/status", ['status' => 'completed'])->assertOk();
        $requestedCancelled = $this->appointment('requested');
        $this->postJson("/api/admin/appointments/{$requestedCancelled}/cancel", ['cancellation_reason' => 'Solicitud retirada'])->assertOk();
        $confirmedProgress = $this->appointment('confirmed');
        $this->postJson("/api/admin/appointments/{$confirmedProgress}/status", ['status' => 'in_progress'])->assertOk();
        $this->postJson("/api/admin/appointments/{$confirmedProgress}/status", ['status' => 'completed'])->assertOk();
        $confirmedNoShow = $this->appointment('confirmed');
        $this->postJson("/api/admin/appointments/{$confirmedNoShow}/status", ['status' => 'no_show'])->assertOk();
        $confirmedCancelled = $this->appointment('confirmed');
        $this->postJson("/api/admin/appointments/{$confirmedCancelled}/cancel", ['cancellation_reason' => 'Cancelación confirmada'])->assertOk();
        $progressCancelled = $this->appointment('confirmed');
        $this->postJson("/api/admin/appointments/{$progressCancelled}/status", ['status' => 'in_progress'])->assertOk();
        $this->postJson("/api/admin/appointments/{$progressCancelled}/cancel", ['cancellation_reason' => 'Cancelación durante atención'])->assertOk();
        $this->patchJson("/api/admin/appointments/{$confirmedNoShow}", ['title' => 'No permitido'])->assertUnprocessable();
    }

    public function test_hard_blocks_cannot_be_overridden(): void
    {
        $this->admin();
        $noSchedule = Employee::create(['branch_id' => $this->branch('NOS')->id, 'name' => 'Sin horario', 'job_title' => 'Técnico', 'is_active' => true]);
        $override = ['availability_override' => true, 'availability_override_reason' => 'Intento de bypass'];
        $this->postJson('/api/admin/appointments', [...$this->payload(), ...$override, 'responsible_employee_id' => $noSchedule->id])
            ->assertUnprocessable()->assertJsonValidationErrors('starts_at');
        $inactive = $this->employee();
        $inactive->update(['is_active' => false]);
        $this->postJson('/api/admin/appointments', [...$this->payload(), ...$override, 'responsible_employee_id' => $inactive->id])
            ->assertUnprocessable()->assertJsonValidationErrors('responsible_employee_id');
        $onLeave = $this->employee(null, 'Ausente');
        EmployeeLeave::create(['employee_id' => $onLeave->id, 'type' => 'permission', 'status' => 'approved',
            'starts_at' => '2026-08-24 13:30:00', 'ends_at' => '2026-08-24 15:30:00']);
        $this->postJson('/api/admin/appointments', [...$this->payload(), ...$override, 'responsible_employee_id' => $onLeave->id])
            ->assertUnprocessable()->assertJsonValidationErrors('starts_at');
    }

    public function test_reassignment_revalidates_active_employee_and_current_occupancy(): void
    {
        $this->admin();
        $origin = $this->employee(null, 'Origen');
        $occupied = $this->employee(null, 'Ocupado');
        $inactive = $this->employee(null, 'Inactivo');
        $inactive->update(['is_active' => false]);
        $first = $this->postJson('/api/admin/appointments', [...$this->payload(), 'responsible_employee_id' => $origin->id])->assertCreated()->json('data.id');
        $this->postJson('/api/admin/appointments', [...$this->payload(), 'responsible_employee_id' => $occupied->id])->assertCreated();
        $this->patchJson("/api/admin/appointments/{$first}", ['responsible_employee_id' => $occupied->id])
            ->assertUnprocessable()->assertJsonValidationErrors('starts_at');
        $this->patchJson("/api/admin/appointments/{$first}", ['responsible_employee_id' => $inactive->id])
            ->assertUnprocessable()->assertJsonValidationErrors('responsible_employee_id');
        $requested = $this->appointment('requested');
        $this->patchJson("/api/admin/appointments/{$requested}", ['responsible_employee_id' => $occupied->id])->assertOk();
    }

    public function test_branch_rules_for_create_and_reassignment(): void
    {
        $this->admin();
        $baq = $this->branch('BAQ');
        $bog = $this->branch('BOG');
        $employeeWithoutBranch = Employee::create(['name' => 'Sin sede', 'job_title' => 'Técnico', 'is_active' => true]);
        $inactiveBranch = $this->branch('INA');
        $inactiveBranch->update(['is_active' => false]);
        $employeeInactiveBranch = Employee::create(['branch_id' => $inactiveBranch->id, 'name' => 'Sede inactiva', 'job_title' => 'Técnico', 'is_active' => true]);
        $baqEmployee = $this->employee(null, 'BAQ A', $baq);
        $baqEmployeeTwo = $this->employee(null, 'BAQ B', $baq);
        $bogEmployee = $this->employee(null, 'BOG A', $bog);

        $this->postJson('/api/admin/appointments', [...$this->payload(), 'responsible_employee_id' => $employeeWithoutBranch->id])
            ->assertUnprocessable()->assertJsonValidationErrors('responsible_employee_id');
        $this->postJson('/api/admin/appointments', [...$this->payload(), 'responsible_employee_id' => $employeeInactiveBranch->id])
            ->assertUnprocessable()->assertJsonValidationErrors('responsible_employee_id');

        $appointmentId = $this->postJson('/api/admin/appointments', [...$this->payload(), 'responsible_employee_id' => $baqEmployee->id])
            ->assertCreated()->json('data.id');
        $this->patchJson("/api/admin/appointments/{$appointmentId}", ['responsible_employee_id' => $baqEmployeeTwo->id])
            ->assertOk()->assertJsonPath('data.branch_id', $baq->id);
        $this->patchJson("/api/admin/appointments/{$appointmentId}", ['responsible_employee_id' => $bogEmployee->id])
            ->assertUnprocessable()->assertJsonValidationErrors('responsible_employee_id');

        $legacy = Appointment::create([...$this->payloadForDatabase(), 'branch_id' => null, 'status' => Appointment::STATUS_REQUESTED]);
        $this->patchJson("/api/admin/appointments/{$legacy->id}", ['responsible_employee_id' => $bogEmployee->id])
            ->assertOk()
            ->assertJsonPath('data.branch_id', $bog->id)
            ->assertJsonPath('data.branch.id', $bog->id);
    }

    public function test_my_appointments_enforce_ownership_filters_and_privacy(): void
    {
        $userA = User::factory()->create(['role' => User::ROLE_USER]);
        $userB = User::factory()->create(['role' => User::ROLE_USER]);
        $employeeA = $this->employee($userA);
        $employeeB = $this->employee($userB, 'Otro');
        $this->admin();
        $a = $this->postJson('/api/admin/appointments', [...$this->payload(), 'responsible_employee_id' => $employeeA->id])->assertCreated()->json('data.id');
        $b = $this->postJson('/api/admin/appointments', [...$this->payload(), 'responsible_employee_id' => $employeeB->id, 'status' => 'requested'])->assertCreated()->json('data.id');
        Sanctum::actingAs($userA);
        $response = $this->getJson('/api/my/appointments?status=confirmed&from=2026-08-24T08:00:00-05:00&to=2026-08-24T11:00:00-05:00')
            ->assertOk()->assertJsonPath('data.appointments.total', 1)->assertJsonMissingPath('data.appointments.data.0.contact_email')
            ->assertJsonMissingPath('data.appointments.data.0.contact_phone')->assertJsonMissingPath('data.appointments.data.0.created_by');
        $this->assertStringNotContainsString('permissions', $response->getContent());
        $this->getJson("/api/my/appointments/{$b}")->assertNotFound();
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_USER]));
        $this->getJson('/api/my/appointments')->assertOk()->assertJsonPath('data.employee_linked', false)->assertJsonCount(0, 'data.appointments');
        $this->getJson("/api/my/appointments/{$a}")->assertNotFound();
    }

    private function admin(): User
    {
        $user = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function employee(?User $user = null, string $name = 'Técnico A', ?Branch $branch = null): Employee
    {
        $employee = Employee::create(['user_id' => $user?->id, 'branch_id' => ($branch ?? $this->branch())->id,
            'name' => $name, 'job_title' => 'Técnico', 'is_active' => true]);
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

    private function payload(): array
    {
        return ['title' => 'Diagnóstico inicial', 'contact_name' => 'Contacto manual', 'contact_phone' => '3000000000', 'vehicle_description' => 'Vehículo ad hoc', 'starts_at' => '2026-08-24T09:00:00-05:00', 'ends_at' => '2026-08-24T10:00:00-05:00'];
    }

    private function payloadForDatabase(): array
    {
        return ['source' => Appointment::SOURCE_CRM, 'title' => 'Diagnóstico inicial', 'contact_name' => 'Contacto manual',
            'contact_phone' => '3000000000', 'vehicle_description' => 'Vehículo ad hoc',
            'starts_at' => '2026-08-24 14:00:00', 'ends_at' => '2026-08-24 15:00:00'];
    }

    private function appointment(string $status): int
    {
        return $this->postJson('/api/admin/appointments', [...$this->payload(), 'status' => $status])->assertCreated()->json('data.id');
    }

    private function service(): Service
    {
        $category = ServiceCategory::create(['name' => 'Instalaciones', 'slug' => 'instalaciones', 'is_active' => true]);

        return Service::create(['service_category_id' => $category->id, 'name' => 'Instalación', 'slug' => 'instalacion', 'price' => 100000, 'estimated_duration_minutes' => 60, 'is_active' => true]);
    }
}
