<?php

namespace Tests\Feature;

use App\Contracts\LandedCostProvider;
use App\Exceptions\EcommerceShippingException;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderCharge;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\DhlLandedCostProvider;
use App\Services\InternationalCheckoutCostService;
use App\Support\LandedCost\LandedCostCharge;
use App\Support\LandedCost\LandedCostItemData;
use App\Support\LandedCost\LandedCostRequest;
use App\Support\LandedCost\LandedCostResult;
use App\Support\Shipping\ShippingAddressData;
use App\Support\Shipping\ShippingOriginData;
use App\Support\Shipping\ShippingQuoteResult;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DhlLandedCostProviderTest extends TestCase
{
    use RefreshDatabase;

    private const USERNAME = 'dhl-user-for-tests';

    private const PASSWORD = 'dhl-password-for-tests';

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
        Config::set('services.dhl_mydhl', [
            'base_url' => 'https://express.api.dhl.com/mydhlapi/test',
            'username' => self::USERNAME,
            'password' => self::PASSWORD,
            'account_number' => '123456789',
            'timeout' => 10,
            'connect_timeout' => 3,
            'retries' => 1,
        ]);
        Http::preventStrayRequests();
    }

    public function test_maps_official_landed_cost_payload_and_normalizes_duty_tax_and_fee(): void
    {
        $payload = null;
        Http::fake(function (Request $request) use (&$payload) {
            $payload = $request->data();

            return Http::response($this->response(), 200, ['Quotation-Id' => 'DHL-QUOTE-1', 'Invocation-Id' => 'INV-1']);
        });

        $result = $this->provider()->estimate($this->request());

        $this->assertSame('dhl_mydhl', $result->provider);
        $this->assertSame('DHL-QUOTE-1', $result->quoteReference);
        $this->assertSame(130000, $result->totalLandedCost);
        $this->assertTrue($result->estimated);
        $this->assertSame('COP', $result->currency);
        $this->assertSame([LandedCostCharge::TYPE_DUTY, LandedCostCharge::TYPE_TAX, LandedCostCharge::TYPE_FEE], array_map(fn ($charge) => $charge->type, $result->charges));
        $this->assertSame([8000, 12000, 2000], array_map(fn ($charge) => $charge->amount, $result->charges));
        $this->assertSame('CO', $payload['customerDetails']['shipperDetails']['countryCode']);
        $this->assertSame('US', $payload['customerDetails']['receiverDetails']['countryCode']);
        $this->assertSame('8708.99', $payload['items'][0]['commodityCode']);
        $this->assertSame('CO', $payload['items'][0]['manufacturerCountry']);
        $this->assertSame('Accesorio automotriz', $payload['items'][0]['description']);
        $this->assertSame(100000, $payload['items'][0]['unitPrice']);
        $this->assertSame(2, $payload['items'][0]['quantity']);
        $this->assertSame(25000, $payload['charges'][0]['amount']);
        $this->assertSame('freight', $payload['charges'][0]['typeCode']);
        $this->assertSame('OTHERS', $payload['merchantSelectedCarrierName']);
        $this->assertSame(1.5, $payload['packages'][0]['weight']);
        $this->assertSame(30.0, $payload['packages'][0]['dimensions']['length']);
        $this->assertArrayNotHasKey('password', $result->metadata);
        $this->assertArrayNotHasKey('authorization', $result->metadata);
    }

    public function test_carrier_mapping_is_provider_specific(): void
    {
        $request = $this->request(carrier: 'dhl');
        Http::fake(fn (Request $httpRequest) => Http::response($this->response()));
        $this->provider()->estimate($request);
        Http::assertSent(fn (Request $httpRequest) => $httpRequest->data()['merchantSelectedCarrierName'] === 'DHL');

    }

    public function test_currency_mismatch_is_structured(): void
    {
        Http::fake(fn () => Http::response(['products' => [[
            'totalPrice' => [['priceCurrency' => 'USD', 'price' => 10]],
            'detailedPriceBreakdown' => [['priceCurrency' => 'USD', 'breakdown' => []]],
        ]]]));
        $this->assertError(EcommerceShippingException::CURRENCY_MISMATCH, fn () => $this->provider()->estimate($this->request()));
    }

    public function test_authentication_error_is_sanitized(): void
    {
        Http::fake(fn () => Http::response(['detail' => self::PASSWORD], 401));
        $exception = $this->capture(fn () => $this->provider()->estimate($this->request()));
        $this->assertSame(EcommerceShippingException::LANDED_COST_AUTHENTICATION_FAILED, $exception->errorCode);
        $this->assertStringNotContainsString(self::PASSWORD, $exception->getMessage());
        $this->assertStringNotContainsString(self::PASSWORD, json_encode($exception->context, JSON_THROW_ON_ERROR));
    }

    public function test_provider_client_server_and_malformed_errors_are_structured(): void
    {
        Http::fake(fn () => Http::response(['detail' => self::PASSWORD], 422));
        $this->assertError(EcommerceShippingException::LANDED_COST_INVALID_REQUEST, fn () => $this->provider()->estimate($this->request()));
    }

    public function test_connection_error_is_structured(): void
    {
        Http::fake(Http::failedConnection('timeout '.self::PASSWORD));
        $this->assertError(EcommerceShippingException::LANDED_COST_UNAVAILABLE, fn () => $this->provider()->estimate($this->request()));
    }

    public function test_server_error_is_structured(): void
    {
        Http::fake(fn () => Http::response(['detail' => self::PASSWORD], 503));
        $this->assertError(EcommerceShippingException::LANDED_COST_UNAVAILABLE, fn () => $this->provider()->estimate($this->request()));
    }

    public function test_malformed_response_is_structured(): void
    {
        Http::fake(fn () => Http::response(['products' => []]));
        $this->assertError(EcommerceShippingException::MALFORMED_PROVIDER_RESPONSE, fn () => $this->provider()->estimate($this->request()));
    }

    public function test_configuration_and_domestic_requests_are_rejected_without_a_real_request(): void
    {
        Config::set('services.dhl_mydhl.username', null);
        $this->assertError(EcommerceShippingException::LANDED_COST_CONFIG_MISSING, fn () => $this->provider()->estimate($this->request()));
        Config::set('services.dhl_mydhl.username', self::USERNAME);
        $this->assertError(EcommerceShippingException::UNSUPPORTED_DESTINATION, fn () => $this->provider()->estimate($this->request(destinationCountry: 'CO')));
    }

    public function test_calculation_and_apply_only_persist_duty_and_tax_idempotently(): void
    {
        [$order, $quote] = $this->internationalOrder();
        $fake = new LandedCostFake(new LandedCostResult(
            provider: 'fake_landed',
            charges: [
                new LandedCostCharge(LandedCostCharge::TYPE_DUTY, 'DUTY', 'Derechos', 8000, 'COP'),
                new LandedCostCharge(LandedCostCharge::TYPE_TAX, 'TAX', 'Impuestos', 12000, 'COP'),
                new LandedCostCharge(LandedCostCharge::TYPE_FEE, 'FEE', 'Fee no persistible', 2000, 'COP'),
            ],
            totalLandedCost: 130000,
            currency: 'COP',
            quoteReference: 'LC-1',
            estimated: true,
            metadata: ['token' => self::PASSWORD, 'calculated_at' => '2026-09-17T12:00:00Z'],
        ));
        $this->app->instance(LandedCostProvider::class, $fake);
        $service = $this->app->make(InternationalCheckoutCostService::class);

        $result = $service->calculate($order, $quote);
        $this->assertSame(25000, $fake->request->shippingAmount);
        $this->assertSame('coordinadora', $fake->request->carrier);
        $this->assertSame('8708.99', $fake->request->items[0]->hsCode);
        $this->assertSame('CO', $fake->request->items[0]->countryOfOrigin);
        $this->assertArrayNotHasKey('token', $result->metadata);
        $first = $service->apply($order, $quote, $result);
        $second = $service->apply($first, $quote, $result);
        $this->assertSame(2, $second->charges()->whereIn('type', [OrderCharge::TYPE_DUTY, OrderCharge::TYPE_TAX])->count());
        $this->assertSame(45000, $second->charges_total);
        $this->assertSame(145000, $second->total);
        $this->assertSame(0, $second->charges()->where('type', 'fee')->count());
    }

    public function test_missing_customs_and_national_checkout_do_not_call_landed_cost(): void
    {
        [$order, $quote] = $this->internationalOrder(['hs_code' => null]);
        $fake = new LandedCostFake($this->fakeResult());
        $this->app->instance(LandedCostProvider::class, $fake);
        $service = $this->app->make(InternationalCheckoutCostService::class);
        $this->assertError(EcommerceShippingException::MISSING_CUSTOMS_DATA, fn () => $service->calculate($order, $quote));
        $this->assertNull($fake->request);

        [$national, $nationalQuote] = $this->internationalOrder(destinationCountry: 'CO');
        $this->assertNull($service->calculate($national, $nationalQuote));
        $this->assertNull($fake->request);
    }

    public function test_default_binding_is_dhl_and_shipping_provider_remains_independent(): void
    {
        $this->assertInstanceOf(DhlLandedCostProvider::class, $this->app->make(LandedCostProvider::class));
    }

    private function provider(): DhlLandedCostProvider
    {
        return $this->app->make(DhlLandedCostProvider::class);
    }

    private function request(string $destinationCountry = 'US', ?string $carrier = 'coordinadora'): LandedCostRequest
    {
        return new LandedCostRequest(
            origin: new ShippingOriginData(1, 'BAQ', 'CO', 'AT', 'Barranquilla', '080001', 'Carrera 50 # 79-10'),
            destination: new ShippingAddressData('Cliente', '+13055551234', $destinationCountry, 'FL', 'Miami', '33139', '100 Ocean Drive'),
            items: [new LandedCostItemData(1, 'Pantalla multimedia', 'Accesorio automotriz', 'CO', '8708.99', 2, 100000, 1500, 300, 200, 100)],
            shippingAmount: 25000,
            insuranceAmount: 0,
            currency: 'COP',
            carrier: $carrier,
            serviceCode: 'worldwide',
        );
    }

    private function response(): array
    {
        return ['products' => [[
            'totalPrice' => [['priceCurrency' => 'COP', 'price' => 130000]],
            'detailedPriceBreakdown' => [['priceCurrency' => 'COP', 'breakdown' => [
                ['name' => 'TOTAL DUTIES', 'typeCode' => 'DUTY', 'price' => 8000, 'priceCurrency' => 'COP'],
                ['name' => 'TOTAL TAXES', 'typeCode' => 'TAX', 'price' => 12000, 'priceCurrency' => 'COP'],
                ['name' => 'TOTAL FEES', 'typeCode' => 'FEE', 'price' => 2000, 'priceCurrency' => 'COP'],
            ]]],
        ]]];
    }

    /** @return array{Order, ShippingQuoteResult} */
    private function internationalOrder(array $productOverrides = [], string $destinationCountry = 'US'): array
    {
        $branch = Branch::create([
            'code' => 'B'.random_int(10000, 99999), 'slug' => 'baq-'.uniqid(), 'name' => 'Barranquilla', 'city' => 'Barranquilla', 'is_active' => true,
            'ecommerce_priority' => 1, 'country_code' => 'CO', 'state' => 'AT', 'postal_code' => '080001', 'address_line1' => 'Carrera 50 # 79-10',
        ]);
        $product = Product::create([
            'name' => 'Pantalla', 'slug' => 'pantalla-'.uniqid(), 'sku' => 'SKU-'.uniqid(), 'price' => 100000, 'requires_shipping' => true,
            'weight_grams' => 1500, 'length_mm' => 300, 'width_mm' => 200, 'height_mm' => 100, 'country_of_origin' => 'CO',
            'hs_code' => '8708.99', 'customs_description' => 'Accesorio automotriz', 'is_active' => true, 'is_visible' => true, ...$productOverrides,
        ]);
        $order = Order::create([
            'order_number' => 'LC-'.uniqid(), 'branch_id' => $branch->id, 'customer_name' => 'Cliente', 'subtotal' => 100000,
            'discount_total' => 0, 'total' => 100000, 'status' => Order::STATUS_PENDING, 'payment_status' => Order::PAYMENT_UNPAID,
            'fulfillment_type' => Order::FULFILLMENT_SHIPPING,
        ]);
        $order->items()->create([
            'item_type' => OrderItem::ITEM_TYPE_PRODUCT, 'product_id' => $product->id, 'product_name' => $product->name, 'product_sku' => $product->sku,
            'unit_price' => 100000, 'quantity' => 1, 'subtotal' => 100000, 'discount_amount' => 0, 'total' => 100000,
        ]);
        $order->shippingAddress()->create([
            'recipient_name' => 'Cliente', 'recipient_phone' => '+13055551234', 'country_code' => $destinationCountry, 'state' => 'FL',
            'city' => 'Miami', 'postal_code' => '33139', 'address_line1' => '100 Ocean Drive',
        ]);
        $quote = new ShippingQuoteResult('envia', 'worldwide', 'Worldwide', 25000, 'COP', 'ENVIA-1', expiresAt: CarbonImmutable::now()->addHour(), branchId: $branch->id, carrier: 'coordinadora');
        $order->charges()->create(['type' => OrderCharge::TYPE_SHIPPING, 'label' => 'Envío', 'amount' => 25000, 'currency' => 'COP', 'metadata' => ['quote_reference' => 'ENVIA-1']]);
        $order->update(['charges_total' => 25000, 'total' => 125000]);

        return [$order->fresh(), $quote];
    }

    private function fakeResult(): LandedCostResult
    {
        return new LandedCostResult('fake', [], 125000, 'COP', 'LC', true);
    }

    private function capture(callable $callback): EcommerceShippingException
    {
        try {
            $callback();
            $this->fail('Expected EcommerceShippingException.');
        } catch (EcommerceShippingException $exception) {
            return $exception;
        }
    }

    private function assertError(string $code, callable $callback): void
    {
        $this->assertSame($code, $this->capture($callback)->errorCode);
    }
}

class LandedCostFake implements LandedCostProvider
{
    public ?LandedCostRequest $request = null;

    public function __construct(private readonly LandedCostResult $result) {}

    public function estimate(LandedCostRequest $request): LandedCostResult
    {
        $this->request = $request;

        return $this->result;
    }
}
