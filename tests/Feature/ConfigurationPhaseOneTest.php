<?php

namespace Tests\Feature;

use App\Mail\NewOrderNotificationMail;
use App\Mail\OrderCreatedMail;
use App\Models\Branch;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\User;
use App\Services\BusinessSettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ConfigurationPhaseOneTest extends TestCase
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

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_settings_permissions_fallback_update_single_row_and_safe_contract(): void
    {
        $this->getJson('/api/admin/settings')->assertUnauthorized();
        $this->actingAsRole('user');
        $this->getJson('/api/admin/settings')->assertForbidden();
        $this->patchJson('/api/admin/settings', [])->assertForbidden();

        $admin = $this->actingAsRole('admin');
        $this->getJson('/api/admin/settings')->assertOk()
            ->assertJsonPath('data.business.business_name', 'UP GRADE 79')
            ->assertJsonPath('data.sales.quotation_validity_days', 15)
            ->assertJsonPath('data.regional.currency', 'COP')
            ->assertJsonPath('data.regional.timezone', 'America/Bogota')
            ->assertJsonMissingPath('data.app_key')
            ->assertJsonMissingPath('data.smtp_password');
        $this->assertDatabaseCount('business_settings', 0);

        $payload = $this->settingsPayload(['business_name' => 'Upgrade Personalizado', 'quotation_validity_days' => 30]);
        $this->patchJson('/api/admin/settings', $payload)->assertOk()
            ->assertJsonPath('data.business.business_name', 'Upgrade Personalizado')
            ->assertJsonPath('data.sales.quotation_validity_days', 30)
            ->assertJsonPath('data.updated_by.id', $admin->id);
        $this->patchJson('/api/admin/settings', $this->settingsPayload(['business_name' => 'Upgrade Final']))->assertOk();
        $this->assertDatabaseCount('business_settings', 1);
        $this->assertDatabaseHas('business_settings', ['id' => 1, 'business_name' => 'Upgrade Final', 'updated_by' => $admin->id]);
    }

    public function test_settings_validation_rejects_regional_fields_and_invalid_values(): void
    {
        $this->actingAsRole('admin');
        $this->patchJson('/api/admin/settings', $this->settingsPayload([
            'quotation_validity_days' => 0,
            'email' => 'incorrecto',
            'order_notification_email' => 'incorrecto',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]))->assertUnprocessable()->assertJsonValidationErrors([
            'quotation_validity_days', 'email', 'order_notification_email', 'currency', 'timezone',
        ]);
    }

    public function test_quotation_default_uses_settings_explicit_date_wins_and_history_is_frozen(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-14 04:30:00 UTC'));
        $this->actingAsRole('admin');
        $product = $this->product();

        $fallback = $this->postJson('/api/admin/quotations', $this->quotationPayload($product))->assertCreated();
        $fallback->assertJsonPath('data.valid_until', '2026-08-28T00:00:00.000000Z');

        app(BusinessSettingsService::class)->update($this->settingsPayload(['quotation_validity_days' => 30]), auth()->user());
        $configured = $this->postJson('/api/admin/quotations', $this->quotationPayload($product))->assertCreated();
        $configured->assertJsonPath('data.valid_until', '2026-09-12T00:00:00.000000Z');
        $explicit = $this->postJson('/api/admin/quotations', $this->quotationPayload($product, ['valid_until' => '2026-08-20']))->assertCreated();
        $explicit->assertJsonPath('data.valid_until', '2026-08-20T00:00:00.000000Z');
        $this->assertSame('2026-08-28', Quotation::findOrFail($fallback->json('data.id'))->valid_until->toDateString());
    }

    public function test_business_identity_and_notification_email_use_database_then_config_fallback(): void
    {
        Mail::fake();
        config()->set('business.order_notification_email', 'fallback@example.com');
        $product = $this->product();
        $this->withHeader('Idempotency-Key', __METHOD__.'-fallback')->postJson('/api/orders', $this->publicOrderPayload($product))->assertCreated();
        Mail::assertSent(OrderCreatedMail::class, fn (OrderCreatedMail $mail) => str_contains($mail->envelope()->subject, 'UP GRADE 79'));
        Mail::assertSent(NewOrderNotificationMail::class, fn (NewOrderNotificationMail $mail) => $mail->hasTo('fallback@example.com'));

        $admin = User::factory()->create(['role' => 'admin']);
        app(BusinessSettingsService::class)->update($this->settingsPayload([
            'business_name' => 'Negocio QA',
            'whatsapp' => '3001112233',
            'order_notification_email' => 'override@example.com',
        ]), $admin);
        Mail::fake();
        $this->withHeader('Idempotency-Key', __METHOD__.'-configured')->postJson('/api/orders', $this->publicOrderPayload($product))->assertCreated();
        Mail::assertSent(OrderCreatedMail::class, fn (OrderCreatedMail $mail) => str_contains($mail->envelope()->subject, 'Negocio QA') && $mail->business['whatsapp'] === '3001112233');
        Mail::assertSent(NewOrderNotificationMail::class, fn (NewOrderNotificationMail $mail) => $mail->hasTo('override@example.com'));
    }

    public function test_user_admin_api_list_search_filters_create_and_private_fields(): void
    {
        $this->getJson('/api/admin/users')->assertUnauthorized();
        $this->actingAsRole('user');
        $this->getJson('/api/admin/users')->assertForbidden();

        $this->actingAsRole('admin');
        User::factory()->create(['name' => 'Buscable', 'email' => 'search@example.com', 'role' => 'user', 'is_active' => false]);
        $this->getJson('/api/admin/users?search=Buscable&role=user&is_active=0&per_page=1&sort=name&direction=asc')
            ->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.email', 'search@example.com')
            ->assertJsonMissingPath('data.data.0.password')->assertJsonMissingPath('data.data.0.remember_token');

        $created = $this->postJson('/api/admin/users', [
            'name' => 'Nuevo Admin', 'email' => 'nuevo@example.com', 'password' => 'Password123',
            'password_confirmation' => 'Password123', 'role' => 'admin',
        ])->assertCreated()->assertJsonPath('data.role', 'admin')->assertJsonMissingPath('data.password');
        $this->assertTrue(Hash::check('Password123', User::findOrFail($created->json('data.id'))->password));
        $this->postJson('/api/admin/users', [
            'name' => 'Inválido', 'email' => 'nuevo@example.com', 'password' => 'Password123',
            'password_confirmation' => 'Password123', 'role' => 'owner',
        ])->assertUnprocessable()->assertJsonValidationErrors(['email', 'role']);
        $this->deleteJson('/api/admin/users/'.$created->json('data.id'))->assertMethodNotAllowed();
    }

    public function test_user_updates_role_self_protection_and_last_admin_guards(): void
    {
        $admin = $this->actingAsRole('admin');
        $sales = User::factory()->create(['role' => 'user', 'is_active' => true]);
        $this->patchJson("/api/admin/users/{$sales->id}", ['name' => 'Actualizado', 'role' => 'admin'])->assertOk()
            ->assertJsonPath('data.role', 'admin');
        $this->patchJson("/api/admin/users/{$admin->id}", ['is_active' => false])->assertUnprocessable();
        $this->patchJson("/api/admin/users/{$admin->id}", ['role' => 'user'])->assertUnprocessable();
        $this->patchJson("/api/admin/users/{$admin->id}", ['password' => 'Hack12345'])->assertUnprocessable();

        $sales->fresh()->update(['role' => 'user']);
        $inactiveAdmin = User::factory()->create(['role' => 'admin', 'is_active' => false]);
        Sanctum::actingAs($inactiveAdmin);
        $this->patchJson("/api/admin/users/{$admin->id}", ['is_active' => false])->assertForbidden();
        $this->assertTrue($admin->fresh()->is_active);

        $other = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        Sanctum::actingAs($other);
        $this->patchJson("/api/admin/users/{$admin->id}", ['is_active' => false])->assertOk();
        $this->assertTrue($other->fresh()->is_active);
    }

    public function test_admin_and_sales_can_update_own_profile_without_privilege_escalation(): void
    {
        foreach (['admin', 'user'] as $role) {
            $user = $this->actingAsRole($role);
            $this->patchJson('/api/auth/me', ['name' => "Perfil {$role}", 'email' => "perfil-{$role}@example.com"])
                ->assertOk()->assertJsonPath('user.role', $role);
            $this->patchJson('/api/auth/me', ['role' => 'admin', 'is_active' => false])->assertUnprocessable();
            $this->assertSame($role, $user->fresh()->role);
            $this->assertTrue($user->fresh()->is_active);
        }
    }

    public function test_password_change_verifies_current_password_and_never_serializes_hash(): void
    {
        $user = User::factory()->create(['role' => 'user', 'password' => 'OldPassword1']);
        Sanctum::actingAs($user);
        $this->patchJson('/api/auth/me/password', [
            'current_password' => 'incorrecta', 'password' => 'NewPassword2', 'password_confirmation' => 'NewPassword2',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->patchJson('/api/auth/me/password', [
            'current_password' => 'OldPassword1', 'password' => 'NewPassword2', 'password_confirmation' => 'otra',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->patchJson('/api/auth/me/password', [
            'current_password' => 'OldPassword1', 'password' => 'NewPassword2', 'password_confirmation' => 'NewPassword2',
        ])->assertOk()->assertJsonMissingPath('password');
        $this->assertFalse(Hash::check('OldPassword1', $user->fresh()->password));
        $this->assertTrue(Hash::check('NewPassword2', $user->fresh()->password));
    }

    public function test_login_is_limited_to_five_attempts_per_email_and_ip(): void
    {
        $this->withSession([]);
        $headers = ['Origin' => 'http://localhost'];
        $email = 'throttle@example.com';
        RateLimiter::clear($email.'|127.0.0.1');
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'incorrecta'], $headers)->assertUnprocessable();
        }
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'incorrecta'], $headers)->assertStatus(429);

        $valid = User::factory()->create(['email' => 'valid@example.com', 'role' => 'user', 'password' => 'Password123']);
        RateLimiter::clear($valid->email.'|127.0.0.1');
        $this->postJson('/api/auth/login', ['email' => $valid->email, 'password' => 'Password123'], $headers)->assertOk();
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role, 'is_active' => true]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function settingsPayload(array $overrides = []): array
    {
        return [...[
            'business_name' => 'UP GRADE 79', 'legal_name' => null, 'tax_id' => null,
            'phone' => null, 'email' => null, 'whatsapp' => '573236293543', 'address' => null,
            'city' => null, 'quotation_validity_days' => 15, 'order_notification_email' => null,
        ], ...$overrides];
    }

    private function product(): Product
    {
        return Product::create([
            'name' => 'Producto QA', 'slug' => 'producto-'.uniqid(), 'sku' => 'QA-'.uniqid(),
            'price' => 100000, 'cost_price' => 40000, 'is_active' => true, 'is_visible' => true,
        ]);
    }

    private function quotationPayload(Product $product, array $overrides = []): array
    {
        return [...[
            'branch_id' => $this->branch()->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ], ...$overrides];
    }

    private function publicOrderPayload(Product $product): array
    {
        return [
            'customer_name' => 'Cliente QA', 'customer_email' => 'cliente@example.com',
            'customer_phone' => '3000000000', 'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ];
    }

    private function branch(): Branch
    {
        return Branch::firstOrCreate(
            ['code' => 'CONFIG'],
            ['slug' => 'configuracion', 'name' => 'Sede configuración', 'city' => 'Barranquilla', 'is_active' => true],
        );
    }
}
