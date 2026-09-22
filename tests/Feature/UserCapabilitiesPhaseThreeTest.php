<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use App\Models\UserCapability;
use App\Services\CommercialEmployeeContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class UserCapabilitiesPhaseThreeTest extends TestCase
{
    use RefreshDatabase;

    public function createApplication()
    {
        $connection = trim((string) getenv('TEST_DB_CONNECTION'));
        $database = trim((string) getenv('TEST_DB_DATABASE'));

        if ($connection !== 'mysql' || $database === '') {
            throw new RuntimeException('UserCapabilitiesPhaseThreeTest requiere TEST_DB_CONNECTION=mysql y TEST_DB_DATABASE explícita.');
        }

        if (strcasecmp($database, 'upgrade') === 0) {
            throw new RuntimeException('UserCapabilitiesPhaseThreeTest no puede ejecutarse contra la base principal upgrade.');
        }

        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $app['config']->set('database.default', $connection);
        $app['config']->set("database.connections.{$connection}.database", $database);

        if (strcasecmp((string) $app['config']->get("database.connections.{$connection}.database"), 'upgrade') === 0) {
            throw new RuntimeException('La base configurada para UserCapabilitiesPhaseThreeTest no puede ser upgrade.');
        }

        return $app;
    }

    public function test_allowlist_and_effective_permissions_are_strict_and_role_specific(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->assertSame(['notifications.view', 'notifications.update', 'business_overview.view'], $user->permissions());
        $this->assertSame(['admin', 'user'], User::roles());

        UserCapability::create([
            'user_id' => $user->id,
            'capability' => UserCapability::ORDERS_VIEW_OWN,
            'granted_by' => $admin->id,
        ]);
        UserCapability::create([
            'user_id' => $user->id,
            'capability' => 'reports.financials',
            'granted_by' => $admin->id,
        ]);
        UserCapability::create([
            'user_id' => $admin->id,
            'capability' => UserCapability::COMMISSIONS_VIEW_OWN,
            'granted_by' => $admin->id,
        ]);

        $this->assertSame([
            'notifications.view',
            'notifications.update',
            'business_overview.view',
            UserCapability::ORDERS_VIEW_OWN,
        ], $user->fresh()->permissions());
        $this->assertFalse($user->fresh()->hasPermission('reports.financials'));
        $this->assertTrue($admin->fresh()->hasPermission('reports.financials'));
        $this->assertFalse(in_array(UserCapability::COMMISSIONS_VIEW_OWN, $admin->fresh()->permissions(), true));
    }

    public function test_capability_admin_api_requires_authentication_and_users_update(): void
    {
        $target = User::factory()->create(['role' => User::ROLE_USER]);

        $this->getJson("/api/admin/users/{$target->id}/capabilities")->assertUnauthorized();
        $this->putJson("/api/admin/users/{$target->id}/capabilities", [
            'capabilities' => [],
        ])->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_USER]));
        $this->getJson("/api/admin/users/{$target->id}/capabilities")->assertForbidden();
        $this->putJson("/api/admin/users/{$target->id}/capabilities", [
            'capabilities' => [],
        ])->assertForbidden();
    }

    public function test_admin_can_get_replace_and_revoke_a_users_capabilities(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $target = User::factory()->create(['role' => User::ROLE_USER]);
        Sanctum::actingAs($admin);

        $this->getJson("/api/admin/users/{$target->id}/capabilities")
            ->assertOk()
            ->assertJsonPath('data.user.id', $target->id)
            ->assertJsonPath('data.capabilities', [])
            ->assertJsonPath('data.allowed_capabilities', UserCapability::allowed())
            ->assertJsonMissingPath('data.user.password');

        $this->putJson("/api/admin/users/{$target->id}/capabilities", [
            'capabilities' => [
                UserCapability::ORDERS_CREATE_OWN,
                UserCapability::QUOTATIONS_VIEW_OWN,
            ],
        ])->assertOk()
            ->assertJsonPath('data.capabilities', [
                UserCapability::QUOTATIONS_VIEW_OWN,
                UserCapability::ORDERS_CREATE_OWN,
            ]);

        $this->assertDatabaseHas('user_capabilities', [
            'user_id' => $target->id,
            'capability' => UserCapability::ORDERS_CREATE_OWN,
            'granted_by' => $admin->id,
        ]);
        $this->assertTrue($target->fresh()->hasPermission(UserCapability::QUOTATIONS_VIEW_OWN));

        $this->putJson("/api/admin/users/{$target->id}/capabilities", [
            'capabilities' => [],
        ])->assertOk()->assertJsonPath('data.capabilities', []);
        $this->assertDatabaseMissing('user_capabilities', ['user_id' => $target->id]);
        $this->assertSame(['notifications.view', 'notifications.update', 'business_overview.view'], $target->fresh()->permissions());
    }

    public function test_capability_validation_rejects_duplicates_unknown_admin_permissions_and_admin_targets(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $target = User::factory()->create(['role' => User::ROLE_USER]);
        Sanctum::actingAs($admin);

        $this->putJson("/api/admin/users/{$target->id}/capabilities", [
            'capabilities' => [
                UserCapability::ORDERS_VIEW_OWN,
                UserCapability::ORDERS_VIEW_OWN,
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('capabilities.1');

        foreach (['arbitrary.permission', 'reports.financials', 'branches.update', 'notifications.view'] as $capability) {
            $this->putJson("/api/admin/users/{$target->id}/capabilities", [
                'capabilities' => [$capability],
            ])->assertUnprocessable()->assertJsonValidationErrors('capabilities.0');
        }

        $this->putJson("/api/admin/users/{$target->id}/capabilities", [
            'capabilities' => ['grant' => UserCapability::ORDERS_VIEW_OWN],
        ])->assertUnprocessable()->assertJsonValidationErrors('capabilities');

        $this->putJson("/api/admin/users/{$admin->id}/capabilities", [
            'capabilities' => [UserCapability::ORDERS_VIEW_OWN],
        ])->assertUnprocessable()->assertJsonValidationErrors('user');

        $this->assertDatabaseMissing('user_capabilities', ['user_id' => $target->id]);
    }

    public function test_replacing_a_set_preserves_existing_granter_and_records_new_grants(): void
    {
        $firstAdmin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $secondAdmin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $target = User::factory()->create(['role' => User::ROLE_USER]);
        UserCapability::create([
            'user_id' => $target->id,
            'capability' => UserCapability::QUOTATIONS_VIEW_OWN,
            'granted_by' => $firstAdmin->id,
        ]);
        Sanctum::actingAs($secondAdmin);

        $this->putJson("/api/admin/users/{$target->id}/capabilities", [
            'capabilities' => [
                UserCapability::QUOTATIONS_VIEW_OWN,
                UserCapability::ORDERS_VIEW_OWN,
            ],
        ])->assertOk();

        $this->assertDatabaseHas('user_capabilities', [
            'user_id' => $target->id,
            'capability' => UserCapability::QUOTATIONS_VIEW_OWN,
            'granted_by' => $firstAdmin->id,
        ]);
        $this->assertDatabaseHas('user_capabilities', [
            'user_id' => $target->id,
            'capability' => UserCapability::ORDERS_VIEW_OWN,
            'granted_by' => $secondAdmin->id,
        ]);
        $this->assertDatabaseCount('user_capabilities', 2);
    }

    public function test_promoting_a_user_removes_individual_capabilities(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $target = User::factory()->create(['role' => User::ROLE_USER]);
        UserCapability::create([
            'user_id' => $target->id,
            'capability' => UserCapability::ORDERS_VIEW_OWN,
            'granted_by' => $admin->id,
        ]);
        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/users/{$target->id}", [
            'role' => User::ROLE_ADMIN,
        ])->assertOk()->assertJsonPath('data.role', User::ROLE_ADMIN);

        $this->assertDatabaseMissing('user_capabilities', ['user_id' => $target->id]);
        $this->assertTrue($target->fresh()->hasPermission('reports.financials'));
    }

    public function test_auth_returns_effective_permissions_and_reduced_employee_branch_context(): void
    {
        $branch = Branch::create([
            'code' => 'BOG',
            'slug' => 'bogota',
            'name' => 'Bogotá',
            'city' => 'Bogotá',
            'is_active' => true,
        ]);
        $user = User::factory()->create([
            'role' => User::ROLE_USER,
            'password' => 'Password123',
        ]);
        Employee::create([
            'user_id' => $user->id,
            'branch_id' => $branch->id,
            'name' => 'Asesora Comercial',
            'phone' => '3000000000',
            'job_title' => 'Asesora',
            'specialty' => 'Audio',
            'notes' => 'No debe exponerse',
            'is_active' => true,
        ]);
        UserCapability::create([
            'user_id' => $user->id,
            'capability' => UserCapability::QUOTATIONS_CREATE_OWN,
        ]);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'Password123',
        ])->assertOk()
            ->assertJsonPath('user.permissions', [
                'notifications.view',
                'notifications.update',
                'business_overview.view',
                UserCapability::QUOTATIONS_CREATE_OWN,
            ])
            ->assertJsonPath('user.employee.name', 'Asesora Comercial')
            ->assertJsonPath('user.employee.branch.code', 'BOG')
            ->assertJsonMissingPath('user.employee.phone')
            ->assertJsonMissingPath('user.employee.notes')
            ->assertJsonMissingPath('user.employee.branch.slug');

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.employee.branch.id', $branch->id);
    }

    public function test_inactive_user_keeps_capability_data_but_cannot_login(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_USER,
            'is_active' => false,
            'password' => 'Password123',
        ]);
        UserCapability::create([
            'user_id' => $user->id,
            'capability' => UserCapability::ORDERS_VIEW_OWN,
        ]);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'Password123',
        ])->assertUnprocessable();

        $this->assertDatabaseHas('user_capabilities', [
            'user_id' => $user->id,
            'capability' => UserCapability::ORDERS_VIEW_OWN,
        ]);
    }

    public function test_commercial_employee_context_validates_active_branch_and_snapshot(): void
    {
        $branch = Branch::create([
            'code' => 'BAQ',
            'slug' => 'barranquilla',
            'name' => 'Barranquilla',
            'city' => 'Barranquilla',
            'is_active' => true,
        ]);
        $user = User::factory()->create(['role' => User::ROLE_USER, 'is_active' => true]);
        $employee = Employee::create([
            'user_id' => $user->id,
            'branch_id' => $branch->id,
            'name' => 'Comercial',
            'job_title' => 'Asesor',
            'is_active' => true,
        ]);
        $service = app(CommercialEmployeeContext::class);

        $context = $service->resolve($user);
        $this->assertTrue($context['employee']->is($employee));
        $this->assertTrue($context['branch']->is($branch));

        try {
            $service->assertSnapshot($employee, $branch->id + 1);
            $this->fail('El snapshot de sede distinto debía rechazarse.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('branch_id', $exception->errors());
        }

        $branch->update(['is_active' => false]);
        try {
            $service->resolve($user);
            $this->fail('La sede inactiva debía rechazarse.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('branch_id', $exception->errors());
        }
    }
}
