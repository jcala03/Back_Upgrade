<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\InventoryStock;
use App\Models\Payment;
use App\Models\Product;
use App\Models\WebhookEvent;
use App\Providers\AppServiceProvider;
use App\Services\EcommercePaymentService;
use App\Services\PublicCheckoutService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class WompiWebhookPhaseSevenTest extends TestCase
{
    use RefreshDatabase;

    public function createApplication()
    {
        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'upgrade_test',
            'TEST_DB_CONNECTION' => 'mysql', 'TEST_DB_DATABASE' => 'upgrade_test'] as $key => $expected) {
            if (getenv($key) !== $expected) {
                throw new RuntimeException('Webhook tests require explicit upgrade_test configuration.');
            }
        }
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'upgrade_test'
            || DB::selectOne('SELECT DATABASE() AS name')->name !== 'upgrade_test') {
            throw new RuntimeException('Webhook tests require the effective upgrade_test database.');
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Http::preventStrayRequests();
        config(['services.wompi' => [
            'base_url' => 'https://sandbox.wompi.co/v1', 'checkout_url' => 'https://checkout.wompi.co/p/',
            'public_key' => 'pub_test_fixture', 'private_key' => 'prv_test_private_fixture',
            'integrity_secret' => 'test_integrity_fixture', 'events_secret' => 'test_events_fixture',
            'redirect_url' => 'https://shop.example.test/return', 'reservation_minutes' => 30,
        ]]);
    }

    #[DataProvider('checksumLocations')]
    public function test_valid_checksums_are_accepted_statelessly(string $location): void
    {
        $this->fixture();
        $event = $this->event();
        $headers = ['Origin' => 'http://localhost'];
        if ($location !== 'body') {
            $headers['X-Event-Checksum'] = strtoupper($event['signature']['checksum']);
        }
        if ($location === 'header') {
            unset($event['signature']['checksum']);
        }
        Http::fake(['https://sandbox.wompi.co/v1/transactions/tx-123' => Http::response(['data' => $this->transaction()])]);
        $this->postJson('/api/webhooks/wompi', $event, $headers)->assertOk()->assertExactJson(['status' => 'processed'])->assertCookieMissing('laravel_session');
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && $request->url() === 'https://sandbox.wompi.co/v1/transactions/tx-123'
            && $request->hasHeader('Authorization', 'Bearer prv_test_private_fixture') && $request->hasHeader('Accept', 'application/json'));
        $this->assertSame('processed', WebhookEvent::sole()->status);
        $route = app('router')->getRoutes()->match(Request::create('/api/webhooks/wompi', 'POST'));
        $this->assertNotContains(EnsureFrontendRequestsAreStateful::class, app('router')->gatherRouteMiddleware($route));
        $other = app('router')->getRoutes()->match(Request::create('/api/orders', 'POST'));
        $this->assertContains(EnsureFrontendRequestsAreStateful::class, app('router')->gatherRouteMiddleware($other));
    }

    public static function checksumLocations(): array
    {
        return [['body'], ['header'], ['both']];
    }

    public function test_webhook_rate_limit_tolerates_reasonable_bursts_and_enforces_its_high_ceiling(): void
    {
        $this->fixture();
        $event = $this->event();
        Http::fake(['https://sandbox.wompi.co/v1/transactions/tx-123' => Http::response(['data' => $this->transaction()])]);
        $limiterKey = md5('wompi-webhook127.0.0.1');
        RateLimiter::clear($limiterKey);
        $limiter = app(\Illuminate\Cache\RateLimiter::class)->limiter('wompi-webhook');
        $limit = $limiter(Request::create('/api/webhooks/wompi', 'POST'));

        $this->assertSame(AppServiceProvider::WOMPI_WEBHOOK_REQUESTS_PER_MINUTE, $limit->maxAttempts);
        $this->assertSame(60, $limit->decaySeconds);
        $this->assertSame('127.0.0.1', $limit->key);

        for ($attempt = 1; $attempt <= 20; $attempt++) {
            $this->postJson('/api/webhooks/wompi', $event)
                ->assertOk()
                ->assertJsonPath('status', 'processed');
        }

        RateLimiter::increment(
            $limiterKey,
            60,
            AppServiceProvider::WOMPI_WEBHOOK_REQUESTS_PER_MINUTE - 20,
        );
        $this->postJson('/api/webhooks/wompi', $event)
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('message', 'Too Many Attempts.');

        $this->assertDatabaseCount('webhook_events', 1);
        Http::assertSentCount(1);
    }

    #[DataProvider('invalidEvents')]
    public function test_invalid_events_never_query_or_create_ledger(string $case, int $http): void
    {
        $event = $this->event();
        $headers = [];
        switch ($case) {
            case 'disagree': $headers['X-Event-Checksum'] = str_repeat('0', 64);
                break;
            case 'invalid': $event['signature']['checksum'] = str_repeat('0', 64);
                break;
            case 'null_checksum': $event['signature']['checksum'] = null;
                break;
            case 'missing_checksum': unset($event['signature']['checksum']);
                break;
            case 'missing_property': $event['signature']['properties'][] = 'transaction.missing';
                break;
            case 'empty_properties': $event['signature']['properties'] = [];
                break;
            case 'secret': config(['services.wompi.events_secret' => '']);
                break;
            case 'environment': $event['environment'] = 'prod';
                break;
            case 'timestamp': $event['timestamp'] = '123';
                break;
            case 'data': $event['data'] = null;
                break;
            case 'path': $event['signature']['properties'] = ['transaction.*'];
                break;
            case 'object': $event['signature']['properties'] = ['transaction'];
                break;
            case 'float': $event['data']['transaction']['amount_in_cents'] = 1.1;
                break;
            case 'signature': $event['signature'] = 'invalid';
                break;
            case 'event': $event['event'] = [];
                break;
        }
        $this->postJson('/api/webhooks/wompi', $event, $headers)->assertStatus($http);
        Http::assertNothingSent();
        $this->assertDatabaseCount('webhook_events', 0);
    }

    public static function invalidEvents(): array
    {
        return [['disagree', 401], ['invalid', 401], ['null_checksum', 401], ['missing_checksum', 401],
            ['missing_property', 400], ['empty_properties', 400], ['secret', 503], ['environment', 400],
            ['timestamp', 400], ['data', 400], ['path', 400], ['object', 400], ['float', 400], ['signature', 400], ['event', 400]];
    }

    public function test_dynamic_paths_order_and_exact_raw_values(): void
    {
        $this->fixture();
        Http::fake(['*' => Http::response(['data' => $this->transaction()])]);
        $event = $this->event();
        $event['data']['future'] = ['nested' => ['value' => '  exact  ']];
        $event['signature']['properties'] = ['future.nested.value', 'transaction.currency', 'transaction.amount_in_cents', 'transaction.id'];
        $event['signature']['checksum'] = hash('sha256', '  exact  COP24000000tx-123'.$event['timestamp'].'test_events_fixture');
        $this->postJson('/api/webhooks/wompi', $event)->assertOk()->assertJsonPath('status', 'processed');
        $event['signature']['properties'] = array_reverse($event['signature']['properties']);
        $this->postJson('/api/webhooks/wompi', $event)->assertUnauthorized();
        $event['signature']['checksum'] = hash('sha256', 'tx-12324000000COP  exact  '.$event['timestamp'].'test_events_fixture');
        $this->postJson('/api/webhooks/wompi', $event)->assertOk();
        Http::assertSentCount(1);
    }

    public function test_malformed_json_and_non_json_are_controlled(): void
    {
        $this->call('POST', '/api/webhooks/wompi', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{')->assertBadRequest();
        $this->post('/api/webhooks/wompi', ['event' => 'transaction.updated'])->assertStatus(415);
        Http::assertNothingSent();
        $this->assertDatabaseCount('webhook_events', 0);
    }

    public function test_unknown_signed_event_is_ignored_without_ledger_or_private_query(): void
    {
        $event = $this->event();
        $event['event'] = 'nequi_token.updated';
        $this->postJson('/api/webhooks/wompi', $event)->assertOk()->assertExactJson(['status' => 'ignored']);
        Http::assertNothingSent();
        $this->assertDatabaseCount('webhook_events', 0);
    }

    #[DataProvider('statuses')]
    public function test_statuses_preserve_inventory_and_commissions(string $status, string $expected): void
    {
        $this->fixture();
        $before = $this->inventorySnapshot();
        Http::fake(['*' => Http::response(['data' => $this->transaction(['status' => $status, 'status_message' => 'customer@example.test'])])]);
        $response = $this->postJson('/api/webhooks/wompi', $this->event(['status' => $status]))->assertOk()->assertExactJson(['status' => $expected]);
        $this->assertSame($before, $this->inventorySnapshot());
        $ledger = WebhookEvent::sole();
        $this->assertSame($expected, $ledger->status);
        $expected === 'processed' ? $this->assertNotNull($ledger->processed_at) : $this->assertNull($ledger->processed_at);
        $this->assertSame($expected === 'ignored' ? 'WOMPI_UNSUPPORTED_STATUS' : null, $ledger->last_error);
        $serialized = json_encode([$ledger->metadata, $response->json(), $ledger->last_error]);
        foreach (['customer@example.test', 'prv_test_private_fixture', 'test_events_fixture', 'test_integrity_fixture', 'signature', 'checkout'] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $serialized);
        }
    }

    public static function statuses(): array
    {
        return [['PENDING', 'processed'], ['APPROVED', 'processed'], ['DECLINED', 'processed'], ['ERROR', 'processed'], ['VOIDED', 'processed'], ['FUTURE_STATUS', 'ignored']];
    }

    #[DataProvider('providerFailures')]
    public function test_provider_failures_are_sanitized_and_retryable(int $status, string $code, int $requests): void
    {
        $this->fixture();
        $before = $this->snapshot();
        Log::spy();
        Http::fake(['*' => Http::response(['error' => 'prv_test_private_fixture test_events_fixture customer@example.test'], $status)]);
        $this->postJson('/api/webhooks/wompi', $this->event())->assertStatus(503)->assertExactJson(['status' => 'failed']);
        Http::assertSentCount($requests);
        $ledger = WebhookEvent::sole();
        $this->assertSame($code, $ledger->last_error);
        $this->assertTrue($ledger->metadata['retryable']);
        $this->assertSame($before, $this->snapshot());
        foreach (['error', 'warning', 'info', 'debug', 'log'] as $level) {
            Log::shouldNotHaveReceived($level);
        }
        $this->assertStringNotContainsString('fixture', json_encode($ledger->getAttributes()));
    }

    public static function providerFailures(): array
    {
        return [[404, 'WOMPI_TRANSACTION_NOT_FOUND', 1], [401, 'WOMPI_NOT_CONFIGURED', 1], [403, 'WOMPI_NOT_CONFIGURED', 1],
            [500, 'WOMPI_UNAVAILABLE', 2], [503, 'WOMPI_UNAVAILABLE', 2], [429, 'WOMPI_UNAVAILABLE', 1], [302, 'WOMPI_UNAVAILABLE', 1]];
    }

    public function test_transport_exception_is_not_chained_or_logged(): void
    {
        Log::spy();
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('prv_test_private_fixture');
        });
        $this->postJson('/api/webhooks/wompi', $this->event())->assertStatus(503)->assertExactJson(['status' => 'failed']);
        $this->assertSame(2, $attempts);
        $this->assertSame('WOMPI_UNAVAILABLE', WebhookEvent::sole()->last_error);
        Log::shouldNotHaveReceived('error');
    }

    #[DataProvider('badResponses')]
    public function test_invalid_private_responses_are_rejected(mixed $data): void
    {
        Http::fake(['*' => Http::response($data)]);
        $this->postJson('/api/webhooks/wompi', $this->event())->assertStatus(502)->assertExactJson(['status' => 'failed']);
        $this->assertSame('WOMPI_INVALID_RESPONSE', WebhookEvent::sole()->last_error);
    }

    public static function badResponses(): array
    {
        $valid = ['id' => 'tx-123', 'reference' => 'UG79-QA', 'status' => 'APPROVED', 'amount_in_cents' => 24000000, 'currency' => 'COP'];

        return [['not JSON'], [['data' => null]], [['data' => []]], [['data' => array_replace($valid, ['amount_in_cents' => '24000000'])]],
            [['data' => array_replace($valid, ['currency' => 'cop'])]], [['data' => array_replace($valid, ['status_message' => []])]],
            [['data' => array_replace($valid, ['id' => 1])]]];
    }

    #[DataProvider('mismatches')]
    public function test_safe_matching_failures_are_audited_without_mutation(string $case, string $code): void
    {
        $payment = $this->fixture();
        $provider = $this->transaction();
        switch ($case) {
            case 'event_reference': $provider['reference'] = 'other';
                break;
            case 'event_amount': $provider['amount_in_cents']++;
                break;
            case 'event_currency': $provider['currency'] = 'USD';
                break;
            case 'event_id': $provider['id'] = 'tx-other';
                break;
            case 'event_status': $provider['status'] = 'DECLINED';
                break;
            case 'payment_reference': $payment->update(['reference' => 'ug79-qa']);
                break;
            case 'payment_amount': $payment->update(['amount' => 1]);
                break;
            case 'payment_currency': $payment->update(['currency' => 'USD']);
                break;
            case 'payment_id': $payment->update(['transaction_id' => 'tx-other']);
                break;
            case 'missing': $payment->delete();
                break;
            case 'order_currency': $payment->order->update(['currency' => 'USD']);
                break;
            case 'order_origin': $payment->order->update(['origin' => 'crm']);
                break;
        }
        $before = $this->snapshot();
        Http::fake(['*' => Http::response(['data' => $provider])]);
        if ($case === 'payment_id') {
            $this->postJson('/api/webhooks/wompi', $this->event())->assertOk()->assertExactJson(['status' => 'requires_reconciliation']);
            $this->assertSame('TRANSACTION_CONFLICT', $payment->fresh()->reconciliation_reason);
            $this->assertSame('tx-other', $payment->fresh()->transaction_id);
            $this->assertSame('pending', $payment->fresh()->status);
            $after = $this->snapshot();
            unset($before['payments'], $after['payments']);
            $this->assertSame($before, $after);
            $this->postJson('/api/webhooks/wompi', $this->event())->assertOk();
            Http::assertSentCount(1);

            return;
        }
        $this->postJson('/api/webhooks/wompi', $this->event())->assertOk()->assertExactJson(['status' => 'failed']);
        $this->assertSame($code, WebhookEvent::sole()->last_error);
        $this->assertFalse(WebhookEvent::sole()->metadata['retryable']);
        $this->assertSame($before, $this->snapshot());
        $this->postJson('/api/webhooks/wompi', $this->event())->assertOk();
        Http::assertSentCount(1);
    }

    public static function mismatches(): array
    {
        return [['event_reference', 'WOMPI_EVENT_REFERENCE_MISMATCH'], ['event_amount', 'WOMPI_EVENT_AMOUNT_IN_CENTS_MISMATCH'],
            ['event_currency', 'WOMPI_EVENT_CURRENCY_MISMATCH'], ['event_id', 'WOMPI_EVENT_ID_MISMATCH'], ['event_status', 'WOMPI_EVENT_STATUS_MISMATCH'],
            ['payment_reference', 'WOMPI_REFERENCE_MISMATCH'], ['payment_amount', 'WOMPI_AMOUNT_MISMATCH'], ['payment_currency', 'WOMPI_CURRENCY_MISMATCH'],
            ['payment_id', 'WOMPI_TRANSACTION_ID_MISMATCH'], ['missing', 'WOMPI_PAYMENT_NOT_FOUND'], ['order_currency', 'WOMPI_ORDER_MISMATCH'], ['order_origin', 'WOMPI_ORDER_MISMATCH']];
    }

    public function test_duplicate_event_key_ignores_timestamp_and_json_order_but_hashes_original_body(): void
    {
        $this->fixture();
        Http::fake(['*' => Http::response(['data' => $this->transaction()])]);
        $event = $this->event();
        $raw = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->call('POST', '/api/webhooks/wompi', [], [], [], ['CONTENT_TYPE' => 'application/json'], $raw)->assertOk();
        $ledger = WebhookEvent::sole();
        $this->assertSame(hash('sha256', $raw), $ledger->payload_hash);
        $this->assertSame(hash('sha256', json_encode(['test', 'transaction.updated', 'tx-123', 'APPROVED', 'UG79-QA', 24000000, 'COP'])), $ledger->event_key);
        $event['timestamp']++;
        $event['data']['transaction'] = array_reverse($event['data']['transaction'], true);
        $event = $this->sign($event);
        $this->postJson('/api/webhooks/wompi', $event)->assertOk();
        $this->assertDatabaseCount('webhook_events', 1);
        $this->assertSame($ledger->getAttributes(), $ledger->fresh()->getAttributes());
        Http::assertSentCount(1);
    }

    public function test_unique_insert_race_reuses_winning_ledger(): void
    {
        $this->fixture();
        Http::fake(['*' => Http::response(['data' => $this->transaction()])]);
        // Deterministic interleaving: the competing insert wins immediately before Eloquent inserts.
        WebhookEvent::creating(function (WebhookEvent $event) {
            DB::table('webhook_events')->insert($event->getAttributes());
        });
        try {
            $this->postJson('/api/webhooks/wompi', $this->event())->assertOk()->assertJsonPath('status', 'processed');
            $this->postJson('/api/webhooks/wompi', $this->event())->assertOk();
            $this->assertDatabaseCount('webhook_events', 1);
            Http::assertSentCount(1);
        } finally {
            WebhookEvent::flushEventListeners();
        }
    }

    public function test_temporary_failure_can_be_retried_then_deduplicated(): void
    {
        $this->fixture();
        Http::fake(['*' => Http::sequence()->push([], 500)->push([], 500)->push(['data' => $this->transaction()])]);
        $this->postJson('/api/webhooks/wompi', $this->event())->assertStatus(503);
        $this->postJson('/api/webhooks/wompi', $this->event())->assertOk()->assertJsonPath('status', 'processed');
        $this->postJson('/api/webhooks/wompi', $this->event())->assertOk();
        Http::assertSentCount(3);
        $this->assertDatabaseCount('webhook_events', 1);
        $this->assertNull(WebhookEvent::sole()->last_error);
    }

    public function test_missing_private_key_never_sends_http(): void
    {
        config(['services.wompi.private_key' => '']);
        $this->postJson('/api/webhooks/wompi', $this->event())->assertStatus(503);
        $this->assertSame('WOMPI_NOT_CONFIGURED', WebhookEvent::sole()->last_error);
        Http::assertNothingSent();
    }

    public function test_documented_inputs_with_recomputed_signature_and_production_environment(): void
    {
        config(['services.wompi.base_url' => 'https://production.wompi.co/v1',
            'services.wompi.events_secret' => 'prod_events_OcHnIzeBl5socpwByQ4hA52Em3USQ93Z',
            'services.wompi.private_key' => 'prv_prod_fixture']);
        $event = $this->event(['id' => '1234-1610641025-49201', 'amount_in_cents' => 4490000]);
        $event['environment'] = 'prod';
        $event['timestamp'] = 1530291411;
        // The documentation's displayed digest is inconsistent with its sample inputs.
        // Independently recomputed SHA256 of the exact documented concatenation:
        $event['signature']['checksum'] = '5A18EC5E8FDB7DF463E9F94774CBA8F583BA21BD04A09CEFF2EA68A4BC0AEFBE';
        Http::fake(['https://production.wompi.co/v1/transactions/*' => Http::response(['data' => $event['data']['transaction']])]);
        $this->postJson('/api/webhooks/wompi', $event)->assertOk();
        $this->assertSame('WOMPI_PAYMENT_NOT_FOUND', WebhookEvent::sole()->last_error);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer prv_prod_fixture'));
    }

    public function test_optional_event_fields_can_be_absent_but_private_response_remains_complete(): void
    {
        $this->fixture()->update(['transaction_id' => 'tx-123']);
        $before = $this->inventorySnapshot();
        Http::fake(['*' => Http::response(['data' => $this->transaction()])]);
        $event = $this->event();
        unset($event['data']['transaction']['reference'], $event['data']['transaction']['currency'], $event['data']['transaction']['amount_in_cents']);
        $event['signature']['properties'] = ['transaction.id', 'transaction.status'];
        $this->postJson('/api/webhooks/wompi', $this->sign($event))->assertOk()->assertJsonPath('status', 'processed');
        $this->assertSame($before, $this->inventorySnapshot());
    }

    public function test_only_ledger_payment_and_order_tables_receive_writes(): void
    {
        $this->fixture();
        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        Http::fake(['*' => Http::response(['data' => $this->transaction()])]);
        $this->postJson('/api/webhooks/wompi', $this->event())->assertOk();
        $this->assertNotEmpty($writes);
        foreach ($writes as $sql) {
            $this->assertMatchesRegularExpression('/^(insert into|update) `(webhook_events|payments|orders)`/i', $sql);
        }
    }

    #[DataProvider('badConfiguration')]
    public function test_invalid_environment_configuration_fails_closed(string $field, string $value): void
    {
        Log::spy();
        config(['services.wompi.'.$field => $value]);
        $response = $this->postJson('/api/webhooks/wompi', $this->event())->assertStatus(503);
        $this->assertStringNotContainsString($value, $response->getContent());
        Log::shouldNotHaveReceived('error');
        Http::assertNothingSent();
    }

    public static function badConfiguration(): array
    {
        return [['base_url', 'https://untrusted.example.test/v1'], ['private_key', 'prv_prod_fixture'], ['events_secret', 'prod_events_fixture'],
            ['private_key', "prv_test_fixture\r\ninvalid"], ['private_key', 'prv_test_fixture invalid']];
    }

    public function test_client_enforces_timeouts_and_disables_redirects(): void
    {
        $this->fixture();
        Http::fake(function ($request, $options) {
            $this->assertSame(3, $options['connect_timeout']);
            $this->assertSame(10, $options['timeout']);
            $this->assertFalse($options['allow_redirects']);

            return Http::response(['data' => $this->transaction()]);
        });
        $this->postJson('/api/webhooks/wompi', $this->event())->assertOk()->assertJsonPath('status', 'processed');
    }

    private function fixture(): Payment
    {
        $identity = (string) Str::uuid();
        $branch = Branch::create(['name' => 'Sede QA', 'code' => substr($identity, 0, 8), 'slug' => $identity, 'city' => 'Barranquilla', 'is_active' => true]);
        $product = Product::create(['name' => 'Webhook QA', 'slug' => $identity, 'sku' => $identity, 'price' => 120000, 'is_active' => true, 'is_visible' => true, 'requires_shipping' => true]);
        $item = InventoryItem::firstOrCreate(['product_id' => $product->id, 'product_variant_id' => null]);
        InventoryStock::create(['branch_id' => $branch->id, 'inventory_item_id' => $item->id, 'quantity' => 5]);
        [$order, $token] = app(PublicCheckoutService::class)->create([
            'customer_name' => 'Cliente QA', 'customer_email' => 'qa@example.test', 'customer_phone' => '3000000000',
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ], 'order-'.$identity);
        app(PublicCheckoutService::class)->applyPickup($order, $branch->slug);
        app(EcommercePaymentService::class)->initialize($token, 'payment-'.$identity);
        $payment = Payment::sole();
        $payment->update(['reference' => 'UG79-QA']);

        return $payment;
    }

    private function transaction(array $overrides = []): array
    {
        return array_replace(['id' => 'tx-123', 'reference' => 'UG79-QA', 'status' => 'APPROVED', 'amount_in_cents' => 24000000, 'currency' => 'COP',
            'payment_method_type' => 'CARD', 'status_message' => null], $overrides);
    }

    private function event(array $overrides = []): array
    {
        return $this->sign(['event' => 'transaction.updated', 'environment' => 'test', 'timestamp' => 1790000000,
            'sent_at' => '2026-09-21T12:00:00.000Z', 'data' => ['transaction' => $this->transaction($overrides)],
            'signature' => ['properties' => ['transaction.id', 'transaction.status', 'transaction.amount_in_cents']]]);
    }

    private function sign(array $event): array
    {
        $values = '';
        foreach ($event['signature']['properties'] as $property) {
            $values .= data_get($event['data'], $property);
        }
        $event['signature']['checksum'] = hash('sha256', $values.$event['timestamp'].'test_events_fixture');

        return $event;
    }

    private function inventorySnapshot(): array
    {
        $snapshot = $this->snapshot();
        unset($snapshot['payments'], $snapshot['orders']);

        return $snapshot;
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (['payments', 'orders', 'inventory_stocks', 'inventory_movements', 'employee_commissions', 'order_status_histories'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $snapshot;
    }
}
