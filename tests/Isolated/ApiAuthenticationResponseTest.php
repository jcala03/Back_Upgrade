<?php

namespace Tests\Isolated;

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;

/** Real HTTP pipeline; no .env, cached config, SQL connections or stored sessions. */
class ApiAuthenticationResponseTest extends TestCase
{
    public function createApplication(): Application
    {
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->useEnvironmentPath('/__upgrade79_auth_test_no_environment__');
        if ($app->configurationIsCached() || is_file($app->getCachedRoutesPath())) {
            throw new LogicException('Isolated auth tests refuse cached configuration or routes.');
        }
        $this->traitsUsedByTest = class_uses_recursive(static::class);
        $app->afterBootstrapping(LoadConfiguration::class, function (Application $app): void {
            $app->instance('env', 'testing');
            $app['config']->set([
                'app.env' => 'testing', 'app.debug' => false,
                'app.key' => 'base64:'.base64_encode(str_repeat('t', 32)),
                'database.default' => 'forbidden', 'database.connections' => [],
                'cache.default' => 'array', 'session.driver' => 'array',
                'mail.default' => 'array', 'logging.default' => 'null',
            ]);
        });
        $app->make(Kernel::class)->bootstrap();
        Http::preventStrayRequests();

        return $app;
    }

    public static function acceptHeaders(): array
    {
        return [
            'no Accept' => [[]],
            'wildcard' => [['Accept' => '*/*']],
            'browser HTML' => [['Accept' => 'text/html,application/xhtml+xml']],
            'frontend JSON' => [['Accept' => 'application/json']],
        ];
    }

    #[DataProvider('acceptHeaders')]
    public function test_unauthenticated_api_returns_the_existing_json_401_contract(array $headers): void
    {
        $this->assertSame([], config('database.connections'));
        $this->assertFalse(Route::has('login'));

        $response = $this->get('/api/auth/me', $headers);

        $response->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(['message' => 'Unauthenticated.']);
        $this->assertFalse($response->headers->has('Location'));
        $this->assertFalse($response->headers->has('Set-Cookie'));
        Http::assertNothingSent();
    }

    #[DataProvider('acceptHeaders')]
    public function test_authenticated_api_retains_the_existing_user_payload(array $headers): void
    {
        // Supply a real in-memory session container for this mocked identity.
        $this->app['router']->pushMiddlewareToGroup('api', StartSession::class);
        $user = Mockery::mock(User::class)->makePartial();
        $user->forceFill(['id' => 901, 'name' => 'Isolated auth fixture', 'email' => 'auth@example.test',
            'role' => User::ROLE_ADMIN, 'is_active' => true]);
        $user->setRelation('employee', null);
        $user->shouldReceive('loadMissing')->once()->andReturnSelf();
        Sanctum::actingAs($user);
        // Stub existing database-backed reads; no SQL or real user exists.
        $query = Mockery::mock(Builder::class);
        DB::shouldReceive('table')->once()->with('users')->andReturn($query);
        $query->shouldReceive('where')->once()->with('id', 901)->andReturnSelf();
        $query->shouldReceive('value')->once()->with('is_active')->andReturn(true);

        $this->get('/api/auth/me', $headers)->assertOk()
            ->assertExactJson(['user' => [
                'id' => 901, 'name' => 'Isolated auth fixture', 'email' => 'auth@example.test',
                'role' => User::ROLE_ADMIN, 'permissions' => $user->permissions(), 'employee' => null,
            ]]);
        Http::assertNothingSent();
    }

    public function test_web_guest_redirect_is_not_changed(): void
    {
        Route::get('/__isolated/login', fn () => 'login fixture')->name('login');
        Route::get('/__isolated/protected', fn () => 'private fixture')->middleware('auth:sanctum');
        Route::getRoutes()->refreshNameLookups();

        $this->get('/__isolated/protected', ['Accept' => 'text/html'])
            ->assertRedirect('/__isolated/login');
        Http::assertNothingSent();
    }

    public function test_inactive_users_are_still_forbidden(): void
    {
        $this->app['router']->pushMiddlewareToGroup('api', StartSession::class);
        $user = new User;
        $user->forceFill(['id' => 902, 'role' => User::ROLE_USER, 'is_active' => false]);
        Sanctum::actingAs($user);
        $query = Mockery::mock(Builder::class);
        DB::shouldReceive('table')->once()->with('users')->andReturn($query);
        $query->shouldReceive('where')->once()->with('id', 902)->andReturnSelf();
        $query->shouldReceive('value')->once()->with('is_active')->andReturn(false);

        $this->get('/api/auth/me')->assertForbidden()
            ->assertExactJson(['message' => 'Tu cuenta está desactivada.']);
    }

    public function test_other_protected_api_routes_still_require_authentication(): void
    {
        foreach (['/api/my/commissions', '/api/admin/quotations'] as $path) {
            $this->get($path)->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
        }
    }
}
