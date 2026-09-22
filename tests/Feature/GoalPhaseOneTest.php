<?php

namespace Tests\Feature;

use App\Models\CrmNotification;
use App\Models\Employee;
use App\Models\Goal;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GoalPhaseOneTest extends TestCase
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

    public function test_schema_constants_relations_permissions_and_authorization(): void
    {
        $this->assertTrue(Schema::hasTable('goals'));
        foreach (['employee_id', 'target_value', 'current_value', 'completed_by', 'cancelled_by'] as $column) {
            $this->assertTrue(Schema::hasColumn('goals', $column));
        }
        $this->assertSame(['personal', 'assigned'], Goal::sources());
        $this->assertSame(['active', 'completed', 'cancelled'], Goal::statuses());
        $this->assertContains('goal_assigned', CrmNotification::types());
        $this->getJson('/api/admin/goals')->assertUnauthorized();
        $user = User::factory()->create(['role' => User::ROLE_USER]);
        Sanctum::actingAs($user);
        $this->getJson('/api/admin/goals')->assertForbidden();
        $this->assertFalse($user->hasPermission('goals.view'));
        $admin = $this->admin();
        $this->assertTrue($admin->hasPermission('goals.cancel'));
        $this->getJson('/api/admin/goals')->assertOk();
    }

    public function test_personal_goal_uses_session_employee_and_protects_backend_fields(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER, 'is_active' => true]);
        $employee = $this->employee($user);
        Sanctum::actingAs($user);
        $id = $this->postJson('/api/my/goals', $this->payload())->assertCreated()->assertJsonPath('data.source', 'personal')
            ->assertJsonPath('data.metric_type', 'manual')->assertJsonPath('data.progress_percentage', 0)->json('data.id');
        $this->assertDatabaseHas('goals', ['id' => $id, 'employee_id' => $employee->id, 'created_by' => $user->id, 'current_value' => 0]);
        $this->postJson('/api/my/goals', [...$this->payload(), 'employee_id' => 999, 'source' => 'assigned', 'metric_type' => 'sales_amount'])->assertUnprocessable();
        $this->assertDatabaseCount('goals', 1);
        $this->assertDatabaseCount('crm_notifications', 0);
    }

    public function test_user_without_employee_and_inactive_employee_creation_errors_are_clear(): void
    {
        $unlinked = User::factory()->create(['role' => User::ROLE_USER]);
        Sanctum::actingAs($unlinked);
        $this->getJson('/api/my/goals')->assertOk()->assertJsonPath('data.employee_linked', false)->assertJsonCount(0, 'data.goals');
        $this->postJson('/api/my/goals', $this->payload())->assertUnprocessable()->assertJsonValidationErrors('employee');
        $inactiveUser = User::factory()->create(['role' => User::ROLE_USER]);
        $this->employee($inactiveUser, false);
        Sanctum::actingAs($inactiveUser);
        $this->postJson('/api/my/goals', $this->payload())->assertUnprocessable()->assertJsonValidationErrors('employee_id');
    }

    public function test_admin_assigned_goal_active_employee_and_assignment_notification(): void
    {
        $recipient = User::factory()->create(['role' => User::ROLE_USER, 'is_active' => true]);
        $employee = $this->employee($recipient);
        $admin = $this->admin();
        $id = $this->postJson('/api/admin/goals', ['employee_id' => $employee->id, ...$this->payload()])->assertCreated()
            ->assertJsonPath('data.source', 'assigned')->assertJsonPath('data.created_by', $admin->id)->json('data.id');
        $this->assertDatabaseHas('crm_notifications', ['user_id' => $recipient->id, 'type' => 'goal_assigned', 'reference_type' => 'goal', 'reference_id' => $id]);
        $notification = CrmNotification::where('reference_id', $id)->firstOrFail();
        $this->assertSame(['goal_id', 'title', 'target_value', 'unit', 'due_on'], array_keys($notification->data));
        $this->postJson("/api/admin/goals/{$id}/progress", ['current_value' => 150])->assertOk()
            ->assertJsonPath('data.progress_percentage', 150)->assertJsonPath('data.status', 'active');
        $employee->update(['is_active' => false]);
        $this->postJson('/api/admin/goals', ['employee_id' => $employee->id, ...$this->payload(), 'title' => 'No válida'])
            ->assertUnprocessable()->assertJsonValidationErrors('employee_id');
        $inactiveRecipient = User::factory()->create(['role' => User::ROLE_USER, 'is_active' => false]);
        $activeEmployee = $this->employee($inactiveRecipient);
        $this->postJson('/api/admin/goals', ['employee_id' => $activeEmployee->id, ...$this->payload(), 'title' => 'Sin notificación'])->assertCreated();
        $this->assertDatabaseMissing('crm_notifications', ['user_id' => $inactiveRecipient->id, 'type' => 'goal_assigned']);
    }

    public function test_ownership_progress_percentage_and_no_auto_completion(): void
    {
        $userA = User::factory()->create(['role' => User::ROLE_USER]);
        $employeeA = $this->employee($userA);
        $userB = User::factory()->create(['role' => User::ROLE_USER]);
        $employeeB = $this->employee($userB);
        $goalA = $this->goal($employeeA, 'personal', ['target_value' => 100]);
        $goalB = $this->goal($employeeB, 'personal');
        Sanctum::actingAs($userA);
        $this->getJson('/api/my/goals')->assertOk()->assertJsonPath('data.goals.total', 1);
        $this->getJson("/api/my/goals/{$goalB->id}")->assertNotFound();
        $this->postJson("/api/my/goals/{$goalB->id}/progress", ['current_value' => 1])->assertNotFound();
        $this->postJson("/api/my/goals/{$goalB->id}/complete")->assertNotFound();
        $this->postJson("/api/my/goals/{$goalB->id}/cancel")->assertNotFound();
        $this->postJson("/api/my/goals/{$goalA->id}/progress", ['current_value' => 25])->assertOk()->assertJsonPath('data.progress_percentage', 25);
        $this->postJson("/api/my/goals/{$goalA->id}/progress", ['current_value' => 120])->assertOk()->assertJsonPath('data.progress_percentage', 120)->assertJsonPath('data.status', 'active');
        $this->postJson("/api/my/goals/{$goalA->id}/progress", ['current_value' => -1])->assertUnprocessable();
        $qualitative = $this->goal($employeeA, 'assigned', ['target_value' => null]);
        $this->postJson("/api/my/goals/{$qualitative->id}/progress", ['current_value' => 1])->assertOk()->assertJsonPath('data.progress_percentage', null);
    }

    public function test_completion_supports_qualitative_below_target_and_expired_late(): void
    {
        CarbonImmutable::setTestNow('2026-08-21 12:00:00 America/Bogota');
        $user = User::factory()->create(['role' => User::ROLE_USER]);
        $employee = $this->employee($user);
        Sanctum::actingAs($user);
        $goal = $this->goal($employee, 'assigned', ['target_value' => 100, 'current_value' => 10, 'due_on' => '2026-08-20']);
        $this->getJson("/api/my/goals/{$goal->id}")->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.effective_status', 'expired');
        $this->postJson("/api/my/goals/{$goal->id}/complete")->assertOk()->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.completed_after_due_date', true);
        $this->assertDatabaseHas('goals', ['id' => $goal->id, 'completed_by' => $user->id]);
        $this->postJson("/api/my/goals/{$goal->id}/complete")->assertUnprocessable();
        CarbonImmutable::setTestNow();
    }

    public function test_cancellation_and_definition_edit_rules(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);
        $employee = $this->employee($user);
        $personal = $this->goal($employee, 'personal');
        $assigned = $this->goal($employee, 'assigned');
        Sanctum::actingAs($user);
        $this->patchJson("/api/my/goals/{$personal->id}", ['title' => 'Editada', 'target_value' => 20])->assertOk()->assertJsonPath('data.title', 'Editada');
        $this->patchJson("/api/my/goals/{$assigned->id}", ['title' => 'Prohibida'])->assertUnprocessable()->assertJsonValidationErrors('source');
        $this->postJson("/api/my/goals/{$assigned->id}/cancel")->assertUnprocessable();
        $this->postJson("/api/my/goals/{$personal->id}/cancel", ['cancellation_reason' => 'Cambio personal'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertDatabaseHas('goals', ['id' => $personal->id, 'cancelled_by' => $user->id]);
        $this->patchJson("/api/my/goals/{$personal->id}", ['title' => 'Terminal'])->assertUnprocessable();
        $admin = $this->admin();
        $this->postJson("/api/admin/goals/{$assigned->id}/cancel", ['cancellation_reason' => 'Decisión administrativa'])->assertOk();
        $this->assertDatabaseHas('goals', ['id' => $assigned->id, 'cancelled_by' => $admin->id, 'cancellation_reason' => 'Decisión administrativa']);
        $this->assertDatabaseHas('crm_notifications', ['type' => 'goal_cancelled', 'reference_id' => $assigned->id]);
    }

    public function test_admin_material_update_and_reassignment_notifications(): void
    {
        $oldUser = User::factory()->create(['role' => User::ROLE_USER]);
        $newUser = User::factory()->create(['role' => User::ROLE_USER]);
        $old = $this->employee($oldUser);
        $new = $this->employee($newUser);
        $this->admin();
        $goal = $this->goal($old, 'assigned');
        $this->patchJson("/api/admin/goals/{$goal->id}", ['title' => 'Meta actualizada'])->assertOk();
        $this->assertDatabaseHas('crm_notifications', ['user_id' => $oldUser->id, 'type' => 'goal_updated']);
        $this->patchJson("/api/admin/goals/{$goal->id}", ['employee_id' => $new->id])->assertOk();
        $this->assertDatabaseHas('crm_notifications', ['user_id' => $oldUser->id, 'type' => 'goal_reassigned']);
        $this->assertDatabaseHas('crm_notifications', ['user_id' => $newUser->id, 'type' => 'goal_assigned']);
        $personal = $this->goal($old, 'personal');
        $this->patchJson("/api/admin/goals/{$personal->id}", ['employee_id' => $new->id])->assertUnprocessable();
    }

    public function test_effective_status_filters_dates_search_and_get_are_read_only(): void
    {
        CarbonImmutable::setTestNow('2026-08-21 12:00:00 America/Bogota');
        $employee = $this->employee();
        $this->goal($employee, 'assigned', ['title' => 'Vencida especial', 'due_on' => '2026-08-20']);
        $this->goal($employee, 'personal', ['title' => 'Activa', 'starts_on' => '2026-08-01', 'due_on' => '2026-08-21']);
        $completed = $this->goal($employee, 'assigned', ['title' => 'Terminada', 'due_on' => '2026-08-19', 'status' => 'completed']);
        $this->admin();
        $this->getJson('/api/admin/goals?status=expired&search=especial')->assertOk()->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.status', 'active')->assertJsonPath('data.data.0.effective_status', 'expired');
        $this->getJson('/api/admin/goals?status=active')->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson('/api/admin/goals?status=completed&due_to=2026-08-20')->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson("/api/admin/goals?employee_id={$employee->id}&source=personal&starts_from=2026-01-01&starts_to=2026-12-31")
            ->assertOk()->assertJsonPath('data.total', 1);
        $this->assertSame('completed', $completed->fresh()->status);
        CarbonImmutable::setTestNow();
    }

    public function test_inactive_employee_keeps_history_but_user_mutations_stop(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);
        $employee = $this->employee($user);
        $goal = $this->goal($employee, 'personal');
        $employee->update(['is_active' => false]);
        Sanctum::actingAs($user);
        $this->getJson('/api/my/goals')->assertOk()->assertJsonPath('data.goals.total', 1);
        $this->patchJson("/api/my/goals/{$goal->id}", ['title' => 'No'])->assertUnprocessable();
        $this->postJson("/api/my/goals/{$goal->id}/progress", ['current_value' => 1])->assertUnprocessable();
        $this->postJson("/api/my/goals/{$goal->id}/complete")->assertUnprocessable();
        $this->postJson("/api/my/goals/{$goal->id}/cancel")->assertUnprocessable();
    }

    public function test_privacy_calendar_exclusion_and_no_financial_side_effects(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);
        $employee = $this->employee($user);
        $goal = $this->goal($employee, 'personal', ['due_on' => '2026-08-24']);
        Sanctum::actingAs($user);
        $response = $this->getJson('/api/my/goals')->assertOk()->assertJsonMissingPath('data.goals.data.0.employee_id')
            ->assertJsonMissingPath('data.goals.data.0.created_by');
        $this->assertStringNotContainsString('permissions', $response->getContent());
        $this->assertStringNotContainsString('email', $response->getContent());
        $this->getJson('/api/my/calendar?from=2026-08-24T00:00:00-05:00&to=2026-08-25T00:00:00-05:00')->assertOk()->assertJsonCount(0, 'data');
        $counts = [Order::count(), Payment::count(), InventoryMovement::count()];
        $this->postJson("/api/my/goals/{$goal->id}/progress", ['current_value' => 5])->assertOk();
        $this->postJson("/api/my/goals/{$goal->id}/complete")->assertOk();
        $this->assertSame($counts, [Order::count(), Payment::count(), InventoryMovement::count()]);
        $notification = CrmNotification::first();
        if ($notification) {
            $this->assertSame(['goal_id', 'title', 'target_value', 'unit', 'due_on'], array_keys($notification->data));
        }
    }

    private function admin(): User
    {
        $user = User::factory()->create(['role' => User::ROLE_ADMIN]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function employee(?User $user = null, bool $active = true): Employee
    {
        return Employee::create(['user_id' => $user?->id, 'name' => 'Empleado', 'job_title' => 'Técnico', 'is_active' => $active]);
    }

    private function payload(): array
    {
        return ['title' => 'Meta mensual', 'description' => 'Progreso manual', 'target_value' => 100, 'unit' => 'trabajos', 'starts_on' => '2026-08-01', 'due_on' => '2026-08-31'];
    }

    private function goal(Employee $employee, string $source, array $overrides = []): Goal
    {
        return Goal::create(['employee_id' => $employee->id, 'source' => $source, 'title' => 'Meta', 'metric_type' => 'manual',
            'target_value' => 10, 'current_value' => 0, 'unit' => 'trabajos', 'status' => 'active', ...$overrides]);
    }
}
