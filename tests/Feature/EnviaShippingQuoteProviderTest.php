<?php

namespace Tests\Feature;

use App\Contracts\ShippingQuoteProvider;
use App\Exceptions\EcommerceShippingException;
use App\Models\BusinessSetting;
use App\Services\EnviaShippingQuoteProvider;
use App\Services\ShippingQuoteService;
use App\Support\Shipping\ShippingAddressData;
use App\Support\Shipping\ShippingOriginData;
use App\Support\Shipping\ShippingPackageData;
use App\Support\Shipping\ShippingQuoteRequest;
use App\Support\Shipping\ShippingQuoteResult;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EnviaShippingQuoteProviderTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'envia-secret-token-for-tests';

    public function createApplication()
    {
        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        if ($connection = getenv('TEST_DB_CONNECTION')) {
            $app['config']->set('database.default', $connection);
            $app['config']->set("database.connections.{$connection}.database", getenv('TEST_DB_DATABASE') ?: 'upgrade_test');
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.envia', [
            'base_url' => 'https://api-test.envia.com',
            'queries_base_url' => 'https://queries.test.envia.com',
            'api_token' => self::TOKEN,
            'timeout' => 10,
            'connect_timeout' => 3,
            'retries' => 1,
        ]);
        $settings = new BusinessSetting([
            'business_name' => 'Upgrade La 79',
            'phone' => '+573001112233',
            'quotation_validity_days' => 15,
        ]);
        $settings->id = 1;
        $settings->save();
        Http::preventStrayRequests();
    }

    public function test_domestic_colombia_request_resolves_dane_maps_units_and_normalizes_multiple_rates(): void
    {
        $ratePayloads = [];
        $this->fakeSuccessfulTransport(function (Request $request) use (&$ratePayloads) {
            $ratePayloads[] = $request->data();
            $carrier = $request->data()['shipment']['carrier'];

            return Http::response(['meta' => 'rate', 'data' => [[
                'carrierId' => $carrier === 'coordinadora' ? 10 : 20,
                'carrier' => $carrier,
                'carrierDescription' => strtoupper($carrier),
                'serviceId' => 7,
                'service' => $carrier === 'coordinadora' ? 'standard' : 'express',
                'serviceDescription' => $carrier === 'coordinadora' ? 'Estándar' : 'Express',
                'deliveryEstimate' => '2-4 días',
                'totalPrice' => $carrier === 'coordinadora' ? '25000.75' : 32000,
                'currency' => 'COP',
                'api_token' => self::TOKEN,
                'headers' => ['Authorization' => 'Bearer '.self::TOKEN],
            ]]]);
        }, ['coordinadora', 'dhl']);

        $quotes = $this->provider()->quote($this->domesticRequest());

        $this->assertCount(2, $quotes);
        $this->assertSame('envia', $quotes[0]->provider);
        $this->assertSame('coordinadora', $quotes[0]->carrier);
        $this->assertSame(25001, $quotes[0]->amount);
        $this->assertSame('COP', $quotes[0]->currency);
        $this->assertSame(2, $quotes[0]->estimatedDaysMin);
        $this->assertSame(4, $quotes[0]->estimatedDaysMax);
        $this->assertStringStartsWith('envia-', $quotes[0]->quoteReference);
        $this->assertArrayNotHasKey('api_token', $quotes[0]->metadata);
        $this->assertArrayNotHasKey('headers', $quotes[0]->metadata);

        $payload = $ratePayloads[0];
        $this->assertSame('08001000', $payload['origin']['city']);
        $this->assertSame('AT', $payload['origin']['state']);
        $this->assertSame('11001000', $payload['destination']['city']);
        $this->assertSame('DC', $payload['destination']['state']);
        $this->assertSame('COP', $payload['settings']['currency']);
        $this->assertArrayNotHasKey('customsSettings', $payload);
        $this->assertSame(1.5, $payload['packages'][0]['weight']);
        $this->assertSame(30.0, $payload['packages'][0]['dimensions']['length']);
        $this->assertSame(20.0, $payload['packages'][0]['dimensions']['width']);
        $this->assertSame(10.0, $payload['packages'][0]['dimensions']['height']);
        $this->assertSame(2, $payload['packages'][0]['amount']);
        $this->assertSame(200000, $payload['packages'][0]['declaredValue']);
    }

    public function test_colombia_dane_resolution_uses_official_locate_contract_without_bearer_token(): void
    {
        $locateRequests = [];
        $this->fakeSuccessfulTransport(rate: fn () => Http::response($this->rateResponse()), locateRequests: $locateRequests);

        $this->provider()->quote($this->domesticRequest());

        $this->assertCount(2, $locateRequests);
        $this->assertSame(['city' => 'Barranquilla', 'state' => 'AT', 'country' => 'CO'], $locateRequests[0]->data());
        $this->assertFalse($locateRequests[0]->hasHeader('Authorization'));
    }

    public function test_international_request_maps_state_packages_and_documented_customs_fields(): void
    {
        $ratePayload = null;
        $this->fakeSuccessfulTransport(function (Request $request) use (&$ratePayload) {
            $ratePayload = $request->data();

            return Http::response($this->rateResponse('dhl', 'worldwide', 145000, '1-3 business days'));
        }, ['dhl']);

        $quotes = $this->provider()->quote($this->internationalRequest());

        $this->assertCount(1, $quotes);
        $this->assertSame('US', $ratePayload['destination']['country']);
        $this->assertSame('FL', $ratePayload['destination']['state']);
        $this->assertSame('Miami', $ratePayload['destination']['city']);
        $this->assertSame(['exportReason' => 'sale'], $ratePayload['customsSettings']);
        $item = $ratePayload['packages'][0]['items'][0];
        $this->assertSame('Accesorio automotriz', $item['description']);
        $this->assertSame('8708.99', $item['productCode']);
        $this->assertSame(2, $item['quantity']);
        $this->assertSame('CO', $item['countryOfManufacture']);
        $this->assertSame(100000, $item['price']);
        $this->assertSame('COP', $item['currency']);
        $this->assertArrayNotHasKey('dutiesPaymentEntity', $ratePayload['customsSettings']);
        $this->assertSame(1, $quotes[0]->estimatedDaysMin);
        $this->assertSame(3, $quotes[0]->estimatedDaysMax);
    }

    public function test_delivery_date_is_preferred_for_transit_days_and_amount_is_not_scaled_by_100(): void
    {
        $this->fakeSuccessfulTransport(fn () => Http::response(['meta' => 'rate', 'data' => [[
            ...$this->rate('dhl', 'express', 19876.49),
            'deliveryEstimate' => 'texto sin rango',
            'deliveryDate' => ['date' => '2026-10-01', 'dateDifference' => 48, 'timeUnit' => 'hours'],
        ]]]), ['dhl']);

        $quote = $this->provider()->quote($this->domesticRequest())[0];

        $this->assertSame(19876, $quote->amount);
        $this->assertSame(2, $quote->estimatedDaysMin);
        $this->assertSame(2, $quote->estimatedDaysMax);
    }

    public function test_configuration_and_authentication_errors_are_sanitized(): void
    {
        Config::set('services.envia.api_token', null);
        $this->assertShippingError(EcommerceShippingException::PROVIDER_CONFIGURATION_ERROR, fn () => $this->provider()->quote($this->domesticRequest()));

        Config::set('services.envia.api_token', self::TOKEN);
        $status = 401;
        Http::fake(function () use (&$status) {
            return Http::response(['message' => 'Rejected '.self::TOKEN], $status);
        });
        $exception = $this->captureShippingError(fn () => $this->provider()->quote($this->domesticRequest()));
        $this->assertSame(EcommerceShippingException::PROVIDER_AUTHENTICATION_FAILED, $exception->errorCode);
        $this->assertStringNotContainsString(self::TOKEN, $exception->getMessage());
        $this->assertStringNotContainsString(self::TOKEN, json_encode($exception->context, JSON_THROW_ON_ERROR));

        $status = 403;
        $exception = $this->captureShippingError(fn () => $this->provider()->quote($this->domesticRequest()));
        $this->assertSame(EcommerceShippingException::PROVIDER_AUTHENTICATION_FAILED, $exception->errorCode);
    }

    public function test_provider_422_maps_to_invalid_address_without_leaking_response_body(): void
    {
        $this->fakeSuccessfulTransport(fn () => Http::response([
            'error' => ['message' => 'Invalid address '.self::TOKEN],
        ], 422), ['dhl']);

        $exception = $this->captureShippingError(fn () => $this->provider()->quote($this->domesticRequest()));

        $this->assertSame(EcommerceShippingException::INVALID_ADDRESS, $exception->errorCode);
        $this->assertStringNotContainsString(self::TOKEN, $exception->getMessage());
        $this->assertStringNotContainsString(self::TOKEN, json_encode($exception->context, JSON_THROW_ON_ERROR));
    }

    public function test_timeout_and_server_errors_map_to_provider_unavailable(): void
    {
        Http::fake(Http::failedConnection('timeout '.self::TOKEN));
        $exception = $this->captureShippingError(fn () => $this->provider()->quote($this->domesticRequest()));
        $this->assertSame(EcommerceShippingException::PROVIDER_UNAVAILABLE, $exception->errorCode);
        $this->assertStringNotContainsString(self::TOKEN, $exception->getMessage());

        Http::fake(fn () => Http::response(['debug' => self::TOKEN], 503));
        $exception = $this->captureShippingError(fn () => $this->provider()->quote($this->domesticRequest()));
        $this->assertSame(EcommerceShippingException::PROVIDER_UNAVAILABLE, $exception->errorCode);
        $this->assertStringNotContainsString(self::TOKEN, $exception->getMessage());
    }

    public function test_empty_rates_map_to_no_quotes_available(): void
    {
        $this->fakeSuccessfulTransport(fn () => Http::response(['meta' => 'rate', 'data' => []]), ['dhl']);
        $this->assertShippingError(EcommerceShippingException::NO_QUOTES_AVAILABLE, fn () => $this->provider()->quote($this->domesticRequest()));
    }

    public function test_invalid_rate_items_map_to_malformed_provider_response(): void
    {
        $this->fakeSuccessfulTransport(fn () => Http::response(['meta' => 'rate', 'data' => [['carrier' => 'dhl']]]), ['dhl']);
        $this->assertShippingError(EcommerceShippingException::MALFORMED_PROVIDER_RESPONSE, fn () => $this->provider()->quote($this->domesticRequest()));
    }

    public function test_missing_rate_data_maps_to_malformed_provider_response(): void
    {
        $this->fakeSuccessfulTransport(fn () => Http::response(['meta' => 'rate', 'unexpected' => []]), ['dhl']);
        $this->assertShippingError(EcommerceShippingException::MALFORMED_PROVIDER_RESPONSE, fn () => $this->provider()->quote($this->domesticRequest()));
    }

    public function test_default_binding_is_envia_and_fake_can_replace_it_for_shipping_quote_service(): void
    {
        $this->assertInstanceOf(EnviaShippingQuoteProvider::class, $this->app->make(ShippingQuoteProvider::class));

        $fake = new ProviderAgnosticFake;
        $this->app->instance(ShippingQuoteProvider::class, $fake);
        $service = $this->app->make(ShippingQuoteService::class);

        $this->assertInstanceOf(ShippingQuoteService::class, $service);
        $this->assertSame($fake, $this->app->make(ShippingQuoteProvider::class));
    }

    private function provider(): EnviaShippingQuoteProvider
    {
        return $this->app->make(EnviaShippingQuoteProvider::class);
    }

    private function domesticRequest(): ShippingQuoteRequest
    {
        return new ShippingQuoteRequest(
            origin: new ShippingOriginData(1, 'BAQ', 'CO', 'Atlántico', 'Barranquilla', '080001', 'Carrera 50 # 79-10'),
            destination: new ShippingAddressData('Cliente', '+573009998877', 'CO', 'Cundinamarca', 'Bogotá', '110111', 'Calle 100 # 10-20'),
            packages: [$this->package()],
            currency: 'COP',
        );
    }

    private function internationalRequest(): ShippingQuoteRequest
    {
        return new ShippingQuoteRequest(
            origin: new ShippingOriginData(1, 'BAQ', 'CO', 'Atlántico', 'Barranquilla', '080001', 'Carrera 50 # 79-10'),
            destination: new ShippingAddressData('International Customer', '+13055551234', 'US', 'Florida', 'Miami', '33139', '100 Ocean Drive'),
            packages: [$this->package()],
            currency: 'COP',
        );
    }

    private function package(): ShippingPackageData
    {
        return new ShippingPackageData(
            orderItemId: 1,
            content: 'Pantalla multimedia',
            quantity: 2,
            weightGrams: 1500,
            lengthMm: 300,
            widthMm: 200,
            heightMm: 100,
            declaredUnitValue: 100000,
            countryOfOrigin: 'CO',
            hsCode: '8708.99',
            customsDescription: 'Accesorio automotriz',
        );
    }

    private function fakeSuccessfulTransport(callable $rate, array $carriers = ['coordinadora'], ?array &$locateRequests = null): void
    {
        Http::fake(function (Request $request) use ($rate, $carriers, &$locateRequests) {
            if (str_contains($request->url(), 'queries.test.envia.com/state')) {
                $country = $request->data()['country_code'] ?? '';

                return Http::response(['data' => $country === 'US'
                    ? [['name' => 'Florida', 'code_2_digits' => 'FL', 'country_code' => 'US']]
                    : [
                        ['name' => 'Atlántico', 'code_2_digits' => 'AT', 'country_code' => 'CO'],
                        ['name' => 'Cundinamarca', 'code_2_digits' => 'DC', 'country_code' => 'CO'],
                    ]]);
            }
            if (str_ends_with($request->url(), '/locate')) {
                if (is_array($locateRequests)) {
                    $locateRequests[] = $request;
                }
                $city = $request->data()['city'];

                return Http::response($city === 'Barranquilla'
                    ? ['city' => '08001000', 'name' => 'BARRANQUILLA', 'state' => 'AT']
                    : ['city' => '11001000', 'name' => 'BOGOTA', 'state' => 'DC']);
            }
            if (str_contains($request->url(), '/available-carrier/')) {
                return Http::response(['success' => true, 'data' => array_map(
                    fn (string $carrier) => ['id' => 1, 'name' => strtoupper($carrier), 'code' => $carrier],
                    $carriers,
                )]);
            }
            if (str_ends_with($request->url(), '/ship/rate/')) {
                return $rate($request);
            }

            return Http::response([], 404);
        });
    }

    private function rateResponse(string $carrier = 'coordinadora', string $service = 'standard', int|float|string $price = 25000, string $estimate = '2-4 días'): array
    {
        return ['meta' => 'rate', 'data' => [$this->rate($carrier, $service, $price, $estimate)]];
    }

    private function rate(string $carrier, string $service, int|float|string $price, string $estimate = '2-4 días'): array
    {
        return [
            'carrierId' => 10,
            'carrier' => $carrier,
            'carrierDescription' => strtoupper($carrier),
            'serviceId' => 20,
            'service' => $service,
            'serviceDescription' => ucfirst($service),
            'deliveryEstimate' => $estimate,
            'totalPrice' => $price,
            'currency' => 'COP',
        ];
    }

    private function captureShippingError(callable $callback): EcommerceShippingException
    {
        try {
            $callback();
            $this->fail('Expected EcommerceShippingException.');
        } catch (EcommerceShippingException $exception) {
            return $exception;
        }
    }

    private function assertShippingError(string $code, callable $callback): void
    {
        $this->assertSame($code, $this->captureShippingError($callback)->errorCode);
    }
}

class ProviderAgnosticFake implements ShippingQuoteProvider
{
    public function quote(ShippingQuoteRequest $request): array
    {
        return [new ShippingQuoteResult('fake', 'service', 'Service', 1000, $request->currency, 'fake-reference')];
    }
}
