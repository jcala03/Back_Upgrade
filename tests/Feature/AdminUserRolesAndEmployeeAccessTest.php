<?php

namespace Tests\Feature;

use App\Models\CrmNotification;
use App\Models\Employee;
use App\Models\Order;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminUserRolesAndEmployeeAccessTest extends TestCase
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

    public function test_roles_and_permissions_are_definitive_and_sales_is_rejected(): void
    {
        $this->assertSame(['admin', 'user'], User::roles());
        $user = User::factory()->create(['role' => User::ROLE_USER]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->assertTrue($user->isUser());
        $this->assertFalse($user->isAdmin());
        $this->assertSame(['notifications.view', 'notifications.update', 'business_overview.view'], $user->permissions());
        $this->assertTrue($admin->hasPermission('dashboard.financials'));
        $this->assertTrue($admin->hasPermission('employees.delete'));

        Sanctum::actingAs($admin);
        $payload = ['name' => 'Legacy', 'email' => 'legacy@example.com', 'password' => 'Password123', 'password_confirmation' => 'Password123', 'role' => 'sales'];
        $this->postJson('/api/admin/users', $payload)->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->patchJson("/api/admin/users/{$user->id}", ['role' => 'sales'])->assertUnprocessable()->assertJsonValidationErrors('role');
    }

    public function test_legacy_migration_converts_sales_and_changes_database_default(): void
    {
        $migration = require database_path('migrations/2026_08_20_000001_replace_sales_role_with_user.php');
        $migration->down();
        $legacyId = DB::table('users')->insertGetId([
            'name' => 'Legacy', 'email' => 'legacy@example.com', 'password' => Hash::make('Password123'),
            'role' => 'sales', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $migration->up();
        $this->assertSame('user', DB::table('users')->where('id', $legacyId)->value('role'));
        $column = DB::selectOne("SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='role'");
        $this->assertSame('user', trim((string) $column->COLUMN_DEFAULT, "'"));
    }

    public function test_minimal_user_can_auth_profile_password_and_own_notifications_only(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER, 'password' => 'OldPassword1']);
        $notice = CrmNotification::create(['user_id' => $user->id, 'type' => CrmNotification::TYPE_STOCK_LOW, 'severity' => 'warning', 'title' => 'Propia', 'message' => 'Mensaje']);
        $order = Order::create(['order_number' => 'ROLE-TEST-1', 'origin' => Order::ORIGIN_CRM, 'subtotal' => 1000, 'discount_total' => 0, 'total' => 1000, 'status' => Order::STATUS_CONFIRMED, 'payment_status' => Order::PAYMENT_UNPAID]);
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'OldPassword1'])
            ->assertOk()->assertJsonPath('user.role', 'user')->assertJsonPath('user.permissions', ['notifications.view', 'notifications.update', 'business_overview.view']);
        $this->getJson('/api/auth/me')->assertOk();
        $this->patchJson('/api/auth/me', ['name' => 'Nombre propio'])->assertOk();
        $this->patchJson('/api/auth/me/password', ['current_password' => 'OldPassword1', 'password' => 'NewPassword1', 'password_confirmation' => 'NewPassword1'])->assertOk();
        $this->getJson('/api/admin/notifications')->assertOk();
        $this->getJson('/api/admin/notifications/unread-count')->assertOk()->assertJsonPath('data.count', 1);
        $this->postJson("/api/admin/notifications/{$notice->id}/read")->assertOk();
        $this->postJson('/api/admin/notifications/read-all')->assertOk();

        foreach (['dashboard', 'products', 'services', 'inventory', 'orders', 'customers', 'quotations', 'reports/sales', 'settings', 'users', 'employees'] as $path) {
            $this->getJson("/api/admin/{$path}")->assertForbidden();
        }
        $this->postJson("/api/admin/orders/{$order->id}/payments", ['amount' => 1000, 'method' => 'cash'])->assertForbidden();
    }

    public function test_admin_grants_crm_access_atomically_and_rejects_invalid_grants(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));
        $employee = Employee::create(['name' => 'Ana Operativa', 'job_title' => 'Instaladora']);
        $payload = ['email' => 'ana@example.com', 'password' => 'Password123', 'password_confirmation' => 'Password123'];
        $response = $this->postJson("/api/admin/employees/{$employee->id}/crm-access", $payload)
            ->assertCreated()->assertJsonPath('data.user.name', 'Ana Operativa')->assertJsonPath('data.user.email', 'ana@example.com')
            ->assertJsonMissingPath('data.user.password')->assertJsonMissingPath('data.user.role');
        $user = User::findOrFail($response->json('data.user.id'));
        $this->assertSame(User::ROLE_USER, $user->role);
        $this->assertTrue($user->is_active);
        $this->assertTrue(Hash::check('Password123', $user->password));
        $this->assertSame($user->id, $employee->fresh()->user_id);
        $this->postJson("/api/admin/employees/{$employee->id}/crm-access", [...$payload, 'email' => 'second@example.com'])->assertUnprocessable();

        $other = Employee::create(['name' => 'Otra', 'job_title' => 'Técnica']);
        $this->postJson("/api/admin/employees/{$other->id}/crm-access", [...$payload, 'role' => 'admin'])->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->postJson("/api/admin/employees/{$other->id}/crm-access", $payload)->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_user_cannot_grant_access_and_failed_link_rolls_back_user(): void
    {
        $employee = Employee::create(['name' => 'Sin acceso', 'job_title' => 'Técnica']);
        $payload = ['email' => 'rollback@example.com', 'password' => 'Password123', 'password_confirmation' => 'Password123'];
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_USER]));
        $this->postJson("/api/admin/employees/{$employee->id}/crm-access", $payload)->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));
        Employee::updating(function () {
            throw new \RuntimeException('Fallo simulado de vínculo');
        });
        $this->postJson("/api/admin/employees/{$employee->id}/crm-access", $payload)->assertServerError();
        $this->assertDatabaseMissing('users', ['email' => 'rollback@example.com']);
        $this->assertNull($employee->fresh()->user_id);
    }
}
