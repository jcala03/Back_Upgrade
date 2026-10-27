<?php

namespace Tests\Feature;

use App\Contracts\WebPushTransport;
use App\Jobs\SendCrmWebPush;
use App\Models\CrmNotification;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\CrmNotificationService;
use App\Services\CrmWebPush;
use App\Services\VapidWebPushTransport;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class CrmWebPushTest extends TestCase
{
    // Real commits are required here to verify that rollback never dispatches a Push.
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        // Leave no committed fixtures behind for the existing transaction-based test suite.
        try {
            $this->truncateTablesForAllConnections();
        } finally {
            parent::tearDown();
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['webpush.enabled' => true, 'webpush.public_key' => 'test-public',
            'webpush.private_key' => 'test-private', 'webpush.subject' => 'mailto:qa@example.test']);
        Queue::fake();
    }

    private function user(string $role = 'user'): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    private function payload(string $device = 'one'): array
    {
        $encode = fn ($value) => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');

        return ['endpoint' => 'https://fcm.googleapis.com/fcm/send/'.$device, 'expirationTime' => null,
            'keys' => ['p256dh' => $encode("\x04".str_repeat('a', 64)), 'auth' => $encode(str_repeat('b', 16))]];
    }

    private function device(User $user, string $name = 'one'): PushSubscription
    {
        $payload = $this->payload($name);

        return PushSubscription::create(['user_id' => $user->id, 'endpoint_hash' => hash('sha256', $payload['endpoint']),
            'endpoint' => $payload['endpoint'], ...$payload['keys'], 'is_active' => true]);
    }

    private function notice(User $user, string $type = 'payment_received', string $key = 'one'): CrmNotification
    {
        return app(CrmNotificationService::class)->createFor($user, ['type' => $type, 'severity' => 'warning',
            'title' => 'Alerta existente', 'message' => 'Mensaje CRM', 'reference_type' => 'payment', 'dedupe_key' => $key]);
    }

    public function test_real_login_subscription_multiple_devices_encryption_and_public_config(): void
    {
        $user = $this->user();
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk()
            ->assertJsonPath('user.role', 'user');
        $this->getJson('/api/push/config')->assertOk()->assertJsonPath('data.public_key', 'test-public')
            ->assertDontSee('test-private');
        $first = $this->postJson('/api/push/subscriptions', $this->payload())->assertCreated()->json('data.id');
        $this->postJson('/api/push/subscriptions', $this->payload('two'))->assertCreated();
        $this->postJson('/api/push/subscriptions', $this->payload())->assertCreated()->assertJsonPath('data.id', $first);
        $this->assertDatabaseCount('push_subscriptions', 2);
        $this->getJson('/api/push/subscriptions')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonMissingPath('data.0.endpoint')->assertJsonMissingPath('data.0.p256dh')
            ->assertJsonMissingPath('data.0.auth')->assertJsonMissingPath('data.0.user_id');
        $this->assertNotSame($this->payload()['endpoint'], DB::table('push_subscriptions')->where('id', $first)->value('endpoint'));
        $this->deleteJson('/api/push/subscriptions/'.$first)->assertOk();
        $this->assertDatabaseHas('push_subscriptions', ['id' => $first, 'is_active' => false]);
        $this->assertSame(1, PushSubscription::where('is_active', true)->count());
        $this->postJson('/api/push/subscriptions', $this->payload())->assertCreated()->assertJsonPath('data.is_active', true);
    }

    public function test_auth_permissions_ownership_and_no_endpoint_takeover(): void
    {
        $this->getJson('/api/push/config')->assertUnauthorized();
        $owner = $this->user();
        $foreign = $this->device($owner);
        Sanctum::actingAs($this->user());
        $this->getJson('/api/push/subscriptions')->assertOk()->assertJsonCount(0, 'data');
        $this->deleteJson('/api/push/subscriptions/'.$foreign->id)->assertNotFound();
        $this->postJson('/api/push/subscriptions', $this->payload())->assertConflict();
        $this->postJson('/api/push/subscriptions', [...$this->payload('new'), 'user_id' => $owner->id])->assertUnprocessable();
        $this->assertTrue($foreign->fresh()->is_active);
        Sanctum::actingAs($this->user('viewer'));
        $this->getJson('/api/push/config')->assertForbidden();
        $this->postJson('/api/push/subscriptions', $this->payload('viewer'))->assertForbidden();
        Sanctum::actingAs($this->user('admin'));
        $this->getJson('/api/push/config')->assertOk();
        $inactive = $this->user();
        $inactive->update(['is_active' => false]);
        Sanctum::actingAs($inactive);
        $this->getJson('/api/push/config')->assertForbidden();
    }

    public function test_validation_rejects_ssrf_invalid_keys_and_expiration(): void
    {
        Sanctum::actingAs($this->user());
        foreach ([null, [], 123] as $endpoint) {
            $this->postJson('/api/push/subscriptions', [...$this->payload(), 'endpoint' => $endpoint])->assertUnprocessable();
        }
        $this->deleteJson('/api/push/subscriptions/invalid')->assertNotFound();
        foreach (['http://fcm.googleapis.com/send', 'https://127.0.0.1/x', 'https://localhost/x',
            'https://fcm.googleapis.com.attacker.test/x', 'https://fcm.googleapis.com:8080/x',
            'https://user:password@fcm.googleapis.com/x', 'https://example.com/x'] as $endpoint) {
            $this->postJson('/api/push/subscriptions', [...$this->payload(), 'endpoint' => $endpoint])->assertUnprocessable();
        }
        $this->postJson('/api/push/subscriptions', [...$this->payload(), 'keys' => ['p256dh' => 'bad', 'auth' => 'bad']])->assertUnprocessable();
        $this->postJson('/api/push/subscriptions', [...$this->payload(), 'expirationTime' => 1])->assertUnprocessable();
        config(['webpush.enabled' => false]);
        $this->getJson('/api/push/config')->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.public_key', null);
        $this->postJson('/api/push/subscriptions', $this->payload())->assertStatus(503);
    }

    public function test_existing_notification_is_the_only_event_and_only_recipient_devices_are_queued_after_commit(): void
    {
        $admin = $this->user('admin');
        $one = $this->device($admin);
        $two = $this->device($admin, 'two');
        $foreign = $this->device($this->user(), 'foreign');
        DB::beginTransaction();
        $notification = $this->notice($admin);
        Queue::assertNothingPushed();
        DB::commit();
        Queue::assertPushed(SendCrmWebPush::class, 2);
        Queue::assertPushed(SendCrmWebPush::class, fn ($job) => $job->notificationId === $notification->id && $job->subscriptionId === $one->id && $job->queue === 'web-push' && $job->connection === 'database');
        Queue::assertPushed(SendCrmWebPush::class, fn ($job) => $job->subscriptionId === $two->id);
        Queue::assertNotPushed(SendCrmWebPush::class, fn ($job) => $job->subscriptionId === $foreign->id);
        $this->notice($admin); // Existing dedupe key: no second internal record, no second Push.
        Queue::assertPushed(SendCrmWebPush::class, 2);
        $this->assertDatabaseCount('crm_notifications', 1);
        DB::beginTransaction();
        $this->notice($admin, 'stock_low', 'rolled-back');
        DB::rollBack();
        Queue::assertPushed(SendCrmWebPush::class, 2);
        $this->assertDatabaseCount('crm_notifications', 1);
        $this->notice($admin, 'goal_updated', 'no-spam');
        Queue::assertPushed(SendCrmWebPush::class, 2);
        $this->assertDatabaseCount('crm_notifications', 2);
    }

    public function test_push_queue_is_independent_of_the_general_synchronous_queue(): void
    {
        config(['queue.default' => 'sync', 'webpush.connection' => 'database', 'webpush.queue' => 'web-push']);
        $user = $this->user();
        $this->device($user);
        $this->notice($user, 'task_assigned');

        Queue::assertPushed(SendCrmWebPush::class, fn ($job) => $job->connection === 'database' && $job->queue === 'web-push');
        $this->assertGreaterThan((new SendCrmWebPush(1, 1))->timeout, config('queue.connections.database.retry_after'));
        $this->assertGreaterThan(45, config('queue.connections.database.retry_after'));
    }

    public function test_each_required_configuration_value_controls_availability_without_exposing_secrets(): void
    {
        Sanctum::actingAs($this->user());
        foreach (['enabled' => false, 'public_key' => '', 'private_key' => '', 'subject' => ''] as $name => $empty) {
            $original = config('webpush.'.$name);
            config(['webpush.'.$name => $empty]);
            $this->getJson('/api/push/config')->assertOk()->assertExactJson(['data' => ['enabled' => false, 'public_key' => null]]);
            config(['webpush.'.$name => $original]);
        }
        $this->getJson('/api/push/config')->assertOk()->assertExactJson(['data' => ['enabled' => true, 'public_key' => 'test-public']]);
    }

    public function test_distribute_reuses_existing_permissions_instead_of_broadcasting_to_all_users(): void
    {
        $admin = $this->user('admin');
        $normal = $this->user();
        $this->device($admin);
        $this->device($normal, 'normal');
        app(CrmNotificationService::class)->distribute('inventory.view', ['type' => 'stock_low', 'severity' => 'warning', 'title' => 'Stock', 'message' => 'Sede BAQ', 'data' => ['branch_id' => 10]]);
        $this->assertSame([$admin->id], CrmNotification::pluck('user_id')->all());
        Queue::assertPushed(SendCrmWebPush::class, 1);
    }

    public function test_success_uses_same_content_badge_real_routes_and_suppresses_duplicate_delivery(): void
    {
        $this->freezeTime();
        $admin = $this->user('admin');
        $device = $this->device($admin);
        $notice = $this->notice($admin);
        $transport = $this->mock(WebPushTransport::class);
        $transport->shouldReceive('send')->once()->withArgs(fn ($sub, $payload, $options) => $sub->id === $device->id
            && $payload['notification_id'] === $notice->id && $payload['user_id'] === $admin->id
            && $payload['url'] === '/crm/orders' && $payload['title'] === $notice->title && $payload['unread_count'] === 1
            && $options === ['TTL' => 86400, 'urgency' => 'high'])->andReturn(201);
        $job = new SendCrmWebPush($notice->id, $device->id);
        $job->handle($transport, app(CrmWebPush::class));
        $job->handle($transport, app(CrmWebPush::class));
        $this->assertNotNull($device->fresh()->last_sent_at);
        $push = app(CrmWebPush::class);
        foreach (['product' => '/crm/inventory', 'product_variant' => '/crm/inventory', 'quotation' => '/crm/quotations', 'unknown' => '/crm/notifications'] as $reference => $url) {
            $notice->reference_type = $reference;
            $this->assertSame($url, $push->url($notice, $admin));
        }
        $this->assertSame('/crm/notifications', $push->url($notice->fill(['reference_type' => 'product']), $this->user()));
    }

    public function test_job_passes_high_priority_only_for_time_sensitive_operational_types(): void
    {
        $this->freezeTime();
        $admin = $this->user('admin');
        $device = $this->device($admin);
        $urgentTypes = ['task_assigned', 'task_reassigned', 'task_cancelled',
            'appointment_assigned', 'appointment_rescheduled', 'appointment_reassigned', 'appointment_cancelled',
            'payment_received', 'payment_reconciliation_required', 'order_ecommerce_pending'];
        $this->assertCount(10, $urgentTypes);
        foreach ($urgentTypes as $type) {
            $this->assertContains($type, config('webpush.types'));
        }
        foreach (config('webpush.types') as $type) {
            $notice = $this->notice($admin, $type, $type);
            $urgent = in_array($type, $urgentTypes, true);
            $expected = ['TTL' => $urgent ? 86400 : 3600, 'urgency' => $urgent ? 'high' : 'normal'];
            $transport = $this->mock(WebPushTransport::class);
            $transport->shouldReceive('send')->once()->withArgs(fn ($sub, $payload, $options) => $sub->id === $device->id
                && $payload['notification_id'] === $notice->id && $options === $expected)->andReturn(201);
            (new SendCrmWebPush($notice->id, $device->id))->handle($transport, app(CrmWebPush::class));
            $this->assertNotNull($device->fresh()->last_sent_at, $type);
        }
    }

    public function test_queued_alert_ttl_never_extends_its_existing_24_hour_lifetime(): void
    {
        $this->freezeTime();
        $admin = $this->user('admin');
        $device = $this->device($admin);
        foreach ([['task_assigned', 3600, 82800], ['task_assigned', 82800, 3600],
            ['task_assigned', 86399, 1], ['stock_low', 84600, 1800]] as [$type, $age, $ttl]) {
            $notice = $this->notice($admin, $type, $type.$age);
            $notice->forceFill(['created_at' => now()->subSeconds($age)])->save();
            $transport = $this->mock(WebPushTransport::class);
            $transport->shouldReceive('send')->once()->withArgs(fn ($sub, $payload, $options) => $options === [
                'TTL' => $ttl, 'urgency' => $type === 'task_assigned' ? 'high' : 'normal',
            ])->andReturn(201);
            (new SendCrmWebPush($notice->id, $device->id))->handle($transport, app(CrmWebPush::class));
        }
        $transport = $this->mock(WebPushTransport::class);
        $transport->shouldNotReceive('send');
        foreach ([86400, 86401] as $age) {
            $notice = $this->notice($admin, 'task_assigned', 'expired'.$age);
            $notice->forceFill(['created_at' => now()->subSeconds($age)])->save();
            (new SendCrmWebPush($notice->id, $device->id))->handle($transport, app(CrmWebPush::class));
        }
    }

    public function test_expired_provider_subscriptions_are_deactivated_for_404_and_410(): void
    {
        $this->freezeTime();
        $admin = $this->user('admin');
        $notice = $this->notice($admin);
        foreach ([404, 410] as $status) {
            $device = $this->device($admin, (string) $status);
            $transport = $this->mock(WebPushTransport::class);
            $transport->shouldReceive('send')->once()->withArgs(fn ($sub, $payload, $options) => $options === [
                'TTL' => 86400, 'urgency' => 'high',
            ])->andReturn($status);
            (new SendCrmWebPush($notice->id, $device->id))->handle($transport, app(CrmWebPush::class));
            $this->assertFalse($device->fresh()->is_active);
            $this->assertNull($device->fresh()->last_sent_at);
        }
    }

    public function test_no_delivery_for_wrong_owner_inactive_account_revoked_permissions_read_or_expired(): void
    {
        $admin = $this->user('admin');
        $notice = $this->notice($admin);
        $device = $this->device($admin);
        $foreign = $this->device($this->user(), 'foreign');
        $transport = $this->mock(WebPushTransport::class);
        $transport->shouldNotReceive('send');
        (new SendCrmWebPush($notice->id, $foreign->id))->handle($transport, app(CrmWebPush::class));
        $admin->update(['is_active' => false]);
        (new SendCrmWebPush($notice->id, $device->id))->handle($transport, app(CrmWebPush::class));
        $admin->update(['is_active' => true, 'role' => 'user']);
        (new SendCrmWebPush($notice->id, $device->id))->handle($transport, app(CrmWebPush::class));
        $admin->update(['role' => 'admin']);
        $notice->update(['read_at' => now()]);
        (new SendCrmWebPush($notice->id, $device->id))->handle($transport, app(CrmWebPush::class));
        $notice->update(['read_at' => null]);
        $device->update(['expires_at' => now()->subMinute()]);
        (new SendCrmWebPush($notice->id, $device->id))->handle($transport, app(CrmWebPush::class));
        $this->assertFalse($device->fresh()->is_active);
    }

    public function test_personal_operational_alerts_reach_only_the_existing_owner_without_administrative_permission(): void
    {
        $this->freezeTime();
        $user = $this->user();
        $device = $this->device($user);
        $notice = $this->notice($user, 'task_assigned');
        $transport = $this->mock(WebPushTransport::class);
        $transport->shouldReceive('send')->once()->withArgs(fn ($sub, $payload, $options) => $payload['user_id'] === $user->id
            && $payload['url'] === '/crm/notifications' && $options === ['TTL' => 86400, 'urgency' => 'high'])->andReturn(201);
        (new SendCrmWebPush($notice->id, $device->id))->handle($transport, app(CrmWebPush::class));
        $this->getJson('/api/admin/notifications')->assertUnauthorized();
        Sanctum::actingAs($user);
        $this->getJson('/api/admin/notifications')->assertOk()->assertJsonPath('data.unread_count', 1);
        $this->postJson('/api/admin/notifications/'.$notice->id.'/read')->assertOk();
        $this->getJson('/api/admin/notifications/unread-count')->assertOk()->assertJsonPath('data.count', 0);
    }

    public function test_logout_only_deactivates_current_device_and_cannot_deactivate_foreign_endpoint(): void
    {
        $user = $this->user();
        $one = $this->device($user);
        $two = $this->device($user, 'two');
        $foreign = $this->device($this->user(), 'foreign');
        Sanctum::actingAs($user);
        $this->postJson('/api/auth/logout', ['push_endpoint' => $foreign->endpoint])->assertOk();
        $this->assertTrue($foreign->fresh()->is_active);
        Sanctum::actingAs($user);
        $this->postJson('/api/auth/logout', ['push_endpoint' => $one->endpoint])->assertOk();
        $this->assertFalse($one->fresh()->is_active);
        $this->assertTrue($two->fresh()->is_active);
    }

    public function test_real_webpush_encryption_and_vapid_headers_with_fake_http_provider(): void
    {
        $this->freezeTime();
        $keys = VAPID::createVapidKeys();
        $subscriberKeys = VAPID::createVapidKeys();
        config(['webpush.public_key' => $keys['publicKey'], 'webpush.private_key' => $keys['privateKey'],
            'webpush.subject' => 'https://upgradecolombia.com']);
        $history = [];
        $handler = HandlerStack::create(new MockHandler(array_fill(0, 4, new Response(201))));
        $handler->push(Middleware::history($history));
        $client = new WebPush(['VAPID' => ['subject' => config('webpush.subject'),
            'publicKey' => $keys['publicKey'], 'privateKey' => $keys['privateKey']]], [], 15,
            ['handler' => $handler, 'allow_redirects' => false], app(LoggerInterface::class));
        $transport = new class($client) extends VapidWebPushTransport
        {
            public function __construct(private WebPush $webPush) {}

            protected function client(): WebPush
            {
                return $this->webPush;
            }
        };
        $admin = $this->user('admin');
        $device = $this->device($admin);
        $device->update(['p256dh' => $subscriberKeys['publicKey']]);
        foreach ([['task_assigned', 0, 'high', 86400], ['payment_received', 82800, 'high', 3600],
            ['stock_low', 0, 'normal', 3600], ['quotation_expiring', 84600, 'normal', 1800]] as $index => [$type, $age, $urgency, $ttl]) {
            $notice = $this->notice($admin, $type, $type);
            $notice->forceFill(['title' => 'Alerta privada QA', 'created_at' => now()->subSeconds($age)])->save();
            (new SendCrmWebPush($notice->id, $device->id))->handle($transport, app(CrmWebPush::class));
            $this->assertCount($index + 1, $history);
            $request = $history[$index]['request'];
            $this->assertSame($urgency, $request->getHeaderLine('Urgency'));
            $this->assertSame((string) $ttl, $request->getHeaderLine('TTL'));
            $this->assertSame('aes128gcm', $request->getHeaderLine('Content-Encoding'));
            $this->assertStringStartsWith('vapid ', $request->getHeaderLine('Authorization'));
            $this->assertStringContainsString('k='.$keys['publicKey'], $request->getHeaderLine('Authorization'));
            preg_match('/vapid t=([^,]+)/', $request->getHeaderLine('Authorization'), $matches);
            $claims = json_decode(base64_decode(strtr(explode('.', $matches[1])[1], '-_', '+/')), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('https://upgradecolombia.com', $claims['sub']);
            $this->assertSame('https://fcm.googleapis.com', $claims['aud']);
            $this->assertStringNotContainsString('Alerta privada QA', (string) $request->getBody());
            $this->assertFalse($history[$index]['options']['allow_redirects']);
            $this->assertNotNull($device->fresh()->last_sent_at);
        }
    }

    public function test_provider_failure_is_retried_without_logging_endpoint_or_crypto_keys(): void
    {
        $admin = $this->user('admin');
        $device = $this->device($admin);
        $notice = $this->notice($admin);
        $transport = $this->mock(WebPushTransport::class);
        $transport->shouldReceive('send')->once()->andThrow(new \RuntimeException('SECRET '.$device->endpoint.' '.$device->auth));
        $job = new SendCrmWebPush($notice->id, $device->id);
        $this->assertSame(3, $job->tries);
        $this->assertSame([60, 300], $job->backoff());
        $this->expectExceptionMessage('Temporary Web Push transport failure.');
        $job->handle($transport, app(CrmWebPush::class));
    }

    public function test_transient_provider_statuses_retry_and_invalid_stored_endpoints_are_disabled(): void
    {
        $this->freezeTime();
        $user = $this->user();
        $device = $this->device($user);
        $notice = $this->notice($user, 'task_assigned');
        foreach ([429, 500, 503] as $status) {
            $transport = $this->mock(WebPushTransport::class);
            $transport->shouldReceive('send')->once()->withArgs(fn ($sub, $payload, $options) => $options === [
                'TTL' => 86400, 'urgency' => 'high',
            ])->andReturn($status);
            try {
                (new SendCrmWebPush($notice->id, $device->id))->handle($transport, app(CrmWebPush::class));
                $this->fail('Transient provider status must fail the job for retry.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('Temporary Web Push provider failure.', $exception->getMessage());
                $this->assertTrue($device->fresh()->is_active);
                $this->assertNull($device->fresh()->last_sent_at);
            }
        }
        $device->update(['endpoint' => 'https://127.0.0.1/private']);
        $transport = $this->mock(WebPushTransport::class);
        $transport->shouldNotReceive('send');
        (new SendCrmWebPush($notice->id, $device->id))->handle($transport, app(CrmWebPush::class));
        $this->assertFalse($device->fresh()->is_active);
    }

    public function test_non_expiration_client_errors_remain_visible_without_disabling_the_subscription(): void
    {
        $this->freezeTime();
        $user = $this->user();
        $device = $this->device($user);
        $notice = $this->notice($user, 'task_assigned');
        foreach ([400, 401, 403] as $status) {
            $transport = $this->mock(WebPushTransport::class);
            $transport->shouldReceive('send')->once()->withArgs(fn ($sub, $payload, $options) => $options === [
                'TTL' => 86400, 'urgency' => 'high',
            ])->andReturn($status);
            try {
                (new SendCrmWebPush($notice->id, $device->id))->handle($transport, app(CrmWebPush::class));
                $this->fail('Provider rejection must not be recorded as a successful delivery.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('Web Push rejected: check VAPID configuration and subscription (HTTP '.$status.').', $exception->getMessage());
                $this->assertTrue($device->fresh()->is_active);
                $this->assertNull($device->fresh()->last_sent_at);
            }
        }
    }
}
