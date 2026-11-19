<?php

namespace Tests\Isolated;

use App\Http\Middleware\EnsureCrmSessionIsActive;
use App\Models\User;
use App\Services\CrmSessionActivity;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\PersonalAccessToken;
use Mockery;

/** No .env, database connections, saved credentials, network or persistent sessions. */
class CrmSessionInactivityTest extends TestCase
{
    public function createApplication(): Application
    {
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->useEnvironmentPath('/__crm_idle_no_environment__');
        if ($app->configurationIsCached() || is_file($app->getCachedRoutesPath())) {
            throw new \LogicException('Isolated inactivity tests refuse cached configuration.');
        }
        $this->traitsUsedByTest = class_uses_recursive(static::class);
        $app->afterBootstrapping(LoadConfiguration::class, function (Application $app): void {
            $app->instance('env', 'testing');
            $app['config']->set([
                'app.env' => 'testing', 'app.debug' => false,
                'app.key' => 'base64:'.base64_encode(str_repeat('i', 32)),
                'database.default' => 'forbidden', 'database.connections' => [],
                'cache.default' => 'array', 'session.driver' => 'array',
                'mail.default' => 'array', 'logging.default' => 'null',
                'crm_session.idle_minutes' => 30,
            ]);
        });
        $app->make(Kernel::class)->bootstrap();
        Http::preventStrayRequests();

        return $app;
    }

    private function credential(): Request
    {
        $session = new Store('isolated', new ArraySessionHandler(120));
        $session->start();
        $request = Request::create('/api/auth/session');
        $request->setLaravelSession($session);
        $request->setUserResolver(fn () => new User);

        return $request;
    }

    private function expectLogout(): void
    {
        $guard = Mockery::mock();
        $guard->shouldReceive('logout')->once();
        Auth::shouldReceive('guard')->with('web')->once()->andReturn($guard);
    }

    public function test_polling_never_renews_activity_and_exact_deadline_expires(): void
    {
        $request = $this->credential();
        $activity = app(CrmSessionActivity::class);
        $activity->start($request);
        $deadline = $activity->metadata($request)['expires_at'];
        for ($minute = 0; $minute < 29; $minute++) {
            $this->travel(1)->minutes();
            $this->assertTrue($activity->isActive($request));
            $this->assertSame($deadline, $activity->metadata($request)['expires_at']);
        }
        $this->travel(1)->minutes();
        $this->assertFalse($activity->isActive($request));
        Http::assertNothingSent();
    }

    public function test_real_interactions_keep_active_user_for_more_than_thirty_minutes(): void
    {
        $request = $this->credential();
        $activity = app(CrmSessionActivity::class);
        $activity->start($request);
        for ($step = 0; $step < 5; $step++) {
            $this->travel(20)->minutes();
            $this->assertTrue($activity->isActive($request));
            $this->assertSame(now()->timestamp + 1800, $activity->touch($request)['expires_at']);
        }
    }

    public function test_expired_request_is_rejected_before_an_administrative_mutation(): void
    {
        $request = $this->credential();
        $activity = app(CrmSessionActivity::class);
        $activity->start($request);
        $originalId = $request->session()->getId();
        $this->travel(30)->minutes();
        $this->expectLogout();
        $response = (new EnsureCrmSessionIsActive($activity))->handle($request, function () {
            $this->fail('An expired credential must never reach the controller.');
        });
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('SESSION_EXPIRED', $response->getData(true)['code']);
        $this->assertNotSame($originalId, $request->session()->getId());
        $replay = $this->credential();
        $replay->session()->setId($originalId);
        $this->assertFalse($activity->isActive($replay));
    }

    public function test_tabs_share_one_server_clock_but_other_devices_do_not(): void
    {
        $activity = app(CrmSessionActivity::class);
        $first = $this->credential();
        $otherDevice = $this->credential();
        $activity->start($first);
        $activity->start($otherDevice);
        $otherTab = $this->credential();
        $otherTab->session()->setId($first->session()->getId());
        $this->travel(20)->minutes();
        $this->assertTrue($activity->isActive($otherTab));
        $activity->touch($otherTab);
        $this->travel(15)->minutes();
        $this->assertTrue($activity->isActive($first));
        $this->assertFalse($activity->isActive($otherDevice));
    }

    public function test_suspended_browser_cannot_revive_expired_session_with_activity_payload(): void
    {
        $request = $this->credential();
        $activity = app(CrmSessionActivity::class);
        $activity->start($request);
        $request->merge(['last_activity_at' => PHP_INT_MAX, 'idle_minutes' => 1440]);
        $this->travel(2)->hours();
        $this->expectLogout();
        $response = (new EnsureCrmSessionIsActive($activity))->handle($request, fn () => response()->json($activity->touch($request)));
        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_cache_eviction_uses_saved_session_time_not_polling_time(): void
    {
        $request = $this->credential();
        $activity = app(CrmSessionActivity::class);
        $activity->start($request);
        $this->travel(31)->minutes();
        Cache::flush(); // The isolated array store only.
        $this->assertFalse($activity->isActive($request));
    }

    public function test_old_bearer_token_cannot_use_polling_last_used_at_to_stay_alive(): void
    {
        $token = Mockery::mock(PersonalAccessToken::class)->makePartial();
        $token->setDateFormat('Y-m-d H:i:s');
        $token->forceFill(['id' => 123, 'created_at' => now()->subHour(), 'last_used_at' => now()]);
        $token->shouldReceive('delete')->once()->andReturn(true);
        $user = (new User)->withAccessToken($token);
        $request = Request::create('/api/auth/me');
        $request->setUserResolver(fn () => $user);
        $activity = app(CrmSessionActivity::class);
        $this->assertFalse($activity->isActive($request));
        $activity->end($request);
        $this->assertFalse($activity->isActive($request));
    }

    public function test_default_and_configured_timeout_and_metadata_contain_no_credentials(): void
    {
        $request = $this->credential();
        $activity = app(CrmSessionActivity::class);
        $activity->start($request);
        $this->assertSame(1800, $activity->timeoutSeconds());
        config(['crm_session.idle_minutes' => 10]);
        $this->assertSame(600, $activity->timeoutSeconds());
        $metadata = $activity->metadata($request);
        $this->assertSame(['session_id', 'idle_timeout_seconds', 'server_time', 'expires_at'], array_keys($metadata));
        $this->assertStringNotContainsString($request->session()->getId(), json_encode($metadata));
        $this->assertSame([], config('database.connections'));
    }

    public function test_public_and_anonymous_routes_do_not_start_an_idle_session(): void
    {
        $this->get('/api/health')->assertOk();
        $this->get('/api/auth/session')->assertUnauthorized();
        $this->post('/api/auth/activity', ['last_activity_at' => PHP_INT_MAX])->assertUnauthorized();
        $this->get('/api/auth/me')->assertExactJson(['message' => 'Unauthenticated.']);
        Http::assertNothingSent();
    }

    public function test_login_activity_logout_and_reauthentication_use_the_same_cookie_mechanism(): void
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('loadMissing')->andReturnSelf();
        $user->setRawAttributes(['id' => 901, 'name' => 'Isolated user', 'email' => 'idle@example.test',
            'role' => 'admin', 'is_active' => true, 'password' => 'isolated-password-hash']);
        $user->setRelation('employee', null);
        $provider = Mockery::mock(UserProvider::class);
        $provider->shouldReceive('retrieveByCredentials')->andReturn($user);
        $provider->shouldReceive('validateCredentials')->andReturn(true);
        $provider->shouldReceive('rehashPasswordIfRequired')->andReturnNull();
        $provider->shouldReceive('retrieveById')->andReturn($user);
        Auth::provider('isolated', fn () => $provider);
        config(['auth.providers.users.driver' => 'isolated', 'sanctum.stateful' => ['localhost']]);
        $this->withHeader('Origin', 'http://localhost');
        $this->withCredentials();
        $query = Mockery::mock(Builder::class);
        DB::shouldReceive('table')->with('users')->andReturn($query);
        $query->shouldReceive('where')->andReturnSelf();
        $query->shouldReceive('value')->with('is_active')->andReturn(true);
        $cookieName = config('session.cookie');
        $request = function (string $method, string $uri, array $body = []) use ($cookieName) {
            $this->app['auth']->shouldUse('web');
            $this->app['auth']->forgetGuards();
            $result = $this->json($method, $uri, $body);
            if ($cookie = $result->getCookie($cookieName, false)) {
                $this->withUnencryptedCookie($cookieName, $cookie->getValue());
            }

            return $result;
        };
        $request('POST', '/api/auth/login', ['email' => 'idle@example.test', 'password' => 'synthetic'])->assertOk();
        $session = $request('GET', '/api/auth/session')->assertOk()->json('session');
        $request('GET', '/api/auth/me')->assertOk()->assertJsonPath('user.id', 901);
        $this->travel(20)->minutes();
        $renewed = $request('POST', '/api/auth/activity', ['expires_at' => PHP_INT_MAX])->assertOk()->json('session');
        $this->assertSame($session['session_id'], $renewed['session_id']);
        $this->assertSame(now()->timestamp + 1800, $renewed['expires_at']);
        $request('POST', '/api/auth/logout', ['idle_session_id' => str_repeat('0', 64)])->assertStatus(409);
        $request('GET', '/api/auth/session')->assertOk();
        $request('POST', '/api/auth/logout')->assertOk();
        $request('GET', '/api/auth/me')->assertUnauthorized();
        $request('POST', '/api/auth/login', ['email' => 'idle@example.test', 'password' => 'synthetic'])->assertOk();
        $new = $request('GET', '/api/auth/session')->assertOk()->json('session');
        $this->assertNotSame($session['session_id'], $new['session_id']);
        $this->travel(30)->minutes();
        $request('POST', '/api/auth/activity')->assertUnauthorized()->assertJsonPath('code', 'SESSION_EXPIRED');
        $request('GET', '/api/auth/me')->assertUnauthorized();
        Http::assertNothingSent();
    }
}
