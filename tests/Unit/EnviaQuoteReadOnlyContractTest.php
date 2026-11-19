<?php

namespace Tests\Unit;

use App\Exceptions\EcommerceShippingException;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\BusinessSettingsService;
use App\Services\EnviaLocationResolver;
use App\Services\EnviaShippingQuoteProvider;
use App\Services\LogisticsSnapshotResolver;
use App\Support\Shipping\ShippingAddressData;
use App\Support\Shipping\ShippingOriginData;
use App\Support\Shipping\ShippingPackageData;
use App\Support\Shipping\ShippingQuoteRequest;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\TestCase;

/** Real adapter, mocked HTTP and identity; no Laravel environment, SQL, orders or charges. */
class EnviaQuoteReadOnlyContractTest extends TestCase
{
    private $previousContainer;

    private $previousFacade;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousFacade = Facade::getFacadeApplication();
        $app = new Application(dirname(__DIR__, 2));
        $app->instance('config', new Repository(['services' => ['envia' => [
            'base_url' => 'https://api.envia.com', 'queries_base_url' => 'https://queries.envia.com',
            'api_token' => 'isolated-not-a-real-token', 'retries' => 1,
        ]]]));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        Http::swap(new Factory);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacade);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    private function provider(): EnviaShippingQuoteProvider
    {
        $identity = Mockery::mock(BusinessSettingsService::class);
        $identity->shouldReceive('identity')->andReturn(['business_name' => 'Isolated sender', 'phone' => '3000000000']);

        return new EnviaShippingQuoteProvider(new EnviaLocationResolver, $identity);
    }

    private function request(): ShippingQuoteRequest
    {
        return new ShippingQuoteRequest(
            new ShippingOriginData(901, 'ISOLATED', 'CO', 'AT', '08001000', '080001', 'Isolated origin'),
            new ShippingAddressData('Isolated recipient', '3000000000', 'CO', 'AT', '08758000', '083002', 'Isolated destination'),
            [new ShippingPackageData(901, 'Isolated package', 2, 100, 100, 100, 100, 100000, null, null, null)], 'COP',
        );
    }

    private function transport(int $status = 200, ?array $data = null): void
    {
        Http::fake([
            'https://queries.envia.com/available-carrier/*' => Http::response(['data' => [['code' => 'coordinadora']]]),
            'https://api.envia.com/ship/rate/' => Http::response($data ?? ['data' => [[
                'carrier' => 'coordinadora', 'service' => 'standard', 'serviceDescription' => 'Estándar',
                'totalPrice' => 25000, 'currency' => 'COP', 'deliveryEstimate' => '2-4 días',
            ]]], $status),
        ]);
    }

    public function test_production_quote_contract_maps_units_and_never_creates_a_label_or_order(): void
    {
        $this->transport();
        $quotes = $this->provider()->quote($this->request());
        $this->assertCount(1, $quotes);
        $this->assertSame(25000, $quotes[0]->amount);
        $this->assertSame('COP', $quotes[0]->currency);
        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.envia.com/ship/rate/'
                && $request['packages'][0]['weight'] === 0.1
                && $request['packages'][0]['weightUnit'] === 'KG'
                && $request['packages'][0]['dimensions']['length'] === 10.0
                && $request['packages'][0]['amount'] === 2
                && $request['packages'][0]['declaredValue'] === 200000;
        });
        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request) => ! str_contains($request->url(), '/available-carrier/')
            && ! str_ends_with($request->url(), '/ship/rate/'));
        $this->assertStringNotContainsString('isolated-not-a-real-token', json_encode($quotes));
    }

    public function test_invalid_token_fails_without_fallback_rate_or_private_details(): void
    {
        $this->transport(401, ['message' => 'Private provider diagnostic']);
        try {
            $this->provider()->quote($this->request());
            $this->fail('An unauthorized provider response must not produce a rate.');
        } catch (EcommerceShippingException $exception) {
            $this->assertSame(EcommerceShippingException::PROVIDER_AUTHENTICATION_FAILED, $exception->errorCode);
            $this->assertStringNotContainsString('Private provider diagnostic', $exception->getMessage());
        }
    }

    public function test_empty_rates_do_not_become_free_shipping(): void
    {
        $this->transport(200, ['data' => []]);
        try {
            $this->provider()->quote($this->request());
            $this->fail('An empty provider result must not produce free shipping.');
        } catch (EcommerceShippingException $exception) {
            $this->assertSame(EcommerceShippingException::NO_QUOTES_AVAILABLE, $exception->errorCode);
        }
    }

    public function test_variant_logistics_inherit_parent_and_preserve_individual_overrides(): void
    {
        $parent = new Product(['requires_shipping' => true, 'weight_grams' => 100, 'length_mm' => 100, 'width_mm' => 100, 'height_mm' => 100]);
        $variant = new ProductVariant(['weight_grams' => 200, 'length_mm' => null, 'width_mm' => null, 'height_mm' => null]);
        $variant->setRelation('product', $parent);
        $item = Mockery::mock(OrderItem::class)->makePartial();
        $item->shouldReceive('loadMissing')->andReturnSelf();
        $item->forceFill(['id' => 901, 'quantity' => 1, 'unit_price' => 100000, 'product_name' => 'Isolated', 'variant_name' => 'Isolated variant']);
        $item->setRelation('product', $parent);
        $item->setRelation('productVariant', $variant);
        $package = (new LogisticsSnapshotResolver)->resolve($item);
        $this->assertSame(200, $package->weightGrams);
        $this->assertSame(100, $package->lengthMm);
        $this->assertSame(100, $package->widthMm);
        $this->assertSame(100000, $package->declaredUnitValue);
    }
}
