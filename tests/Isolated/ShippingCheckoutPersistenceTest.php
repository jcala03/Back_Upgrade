<?php

namespace Tests\Isolated;

use App\Contracts\ShippingQuoteProvider;
use App\Models\Branch;
use App\Models\BusinessSetting;
use App\Models\InventoryStock;
use App\Services\EnviaShippingQuoteProvider;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\MultimediaFixture;
use Tests\Support\MultimediaSqliteApplication;

/** End-to-end existing shipping routes; SQLite :memory: and HTTP fakes only. */
class ShippingCheckoutPersistenceTest extends TestCase
{
    public function createApplication()
    {
        return MultimediaSqliteApplication::boot();
    }

    protected function setUp(): void
    {
        parent::setUp();
        MultimediaSqliteApplication::migrate($this->app);
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->app->bind(ShippingQuoteProvider::class, EnviaShippingQuoteProvider::class);
        config(['services.envia' => ['base_url' => 'https://api.envia.com', 'queries_base_url' => 'https://queries.envia.com',
            'api_token' => 'isolated-not-a-real-token', 'retries' => 1]]);
    }

    private function checkout(bool $measures = true, bool $origin = true): array
    {
        $fixture = MultimediaFixture::create();
        if ($measures) {
            $fixture['product']->update(['weight_grams' => 100, 'length_mm' => 100, 'width_mm' => 100, 'height_mm' => 100]);
        }
        if ($origin) {
            $fixture['branch']->update(['country_code' => 'CO', 'state' => 'AT', 'city' => '08001000', 'postal_code' => '080001', 'address_line1' => 'Isolated origin']);
        }
        BusinessSetting::create(['business_name' => 'Isolated business', 'phone' => '3000000000', 'quotation_validity_days' => 15]);
        $token = $this->withHeader('Idempotency-Key', 'isolated-shipping')->postJson('/api/orders', $fixture['payload'])
            ->assertCreated()->json('public_token');
        $this->putJson('/api/checkout/orders/'.$token.'/shipping-address', [
            'recipient_name' => 'Isolated recipient', 'recipient_phone' => '3000000000', 'country_code' => 'CO',
            'state' => 'AT', 'city' => '08758000', 'postal_code' => '083002', 'address_line1' => 'Isolated address',
        ])->assertOk();

        return [$token, $fixture];
    }

    public function test_quote_application_server_total_and_pickup_work_without_payments_or_stock_changes(): void
    {
        [$token, $fixture] = $this->checkout();
        Http::fake([
            'https://queries.envia.com/available-carrier/*' => Http::response(['data' => [['code' => 'coordinadora']]]),
            'https://api.envia.com/ship/rate/' => Http::response(['data' => [[
                'carrier' => 'coordinadora', 'service' => 'standard', 'serviceDescription' => 'Estándar',
                'totalPrice' => 25000, 'currency' => 'COP',
            ]]]),
        ]);
        $quote = $this->postJson('/api/checkout/orders/'.$token.'/shipping-quotes')->assertOk()
            ->assertJsonPath('data.quotes.0.amount', 25000)->json('data.quotes.0.quote_token');
        $this->postJson('/api/checkout/orders/'.$token.'/shipping-quotes/apply', ['quote_token' => 'tampered'])
            ->assertUnprocessable()->assertJsonPath('code', 'INVALID_QUOTE');
        $this->postJson('/api/checkout/orders/'.$token.'/shipping-quotes/apply', ['quote_token' => $quote, 'amount' => 1])
            ->assertOk()->assertJsonPath('data.total', 1225000)->assertJsonPath('data.charges_total', 25000)
            ->assertJsonPath('data.fulfillment_type', 'shipping')->assertJsonPath('data.ready_for_payment', true);
        $this->postJson('/api/checkout/orders/'.$token.'/pickup', ['branch_slug' => $fixture['branch']->slug])
            ->assertOk()->assertJsonPath('data.total', 1200000)->assertJsonPath('data.charges_total', 0)
            ->assertJsonPath('data.fulfillment_type', 'pickup')->assertJsonPath('data.ready_for_payment', true);
        $this->assertSame(3, $fixture['stock']->fresh()->quantity);
        $this->assertSame(0, DB::table('payments')->count());
        $this->assertSame(0, DB::table('inventory_movements')->count());
        Http::assertSentCount(2);
    }

    public function test_missing_persisted_measurements_stop_before_provider_call(): void
    {
        [$token] = $this->checkout(measures: false);
        $this->postJson('/api/checkout/orders/'.$token.'/shipping-quotes')->assertUnprocessable()
            ->assertJsonPath('code', 'MISSING_LOGISTICS_DATA');
        Http::assertNothingSent();
    }

    public function test_incomplete_selected_origin_does_not_switch_to_another_branch(): void
    {
        [$token, $fixture] = $this->checkout(origin: false);
        $other = Branch::create(['name' => 'Other isolated branch', 'code' => 'OTHER', 'slug' => 'other',
            'city' => '11001000', 'state' => 'DC', 'country_code' => 'CO', 'postal_code' => '110111',
            'address_line1' => 'Other isolated origin', 'is_active' => true, 'ecommerce_priority' => 999]);
        InventoryStock::create(['branch_id' => $other->id, 'inventory_item_id' => $fixture['item']->id, 'quantity' => 10]);
        $this->postJson('/api/checkout/orders/'.$token.'/shipping-quotes')->assertUnprocessable()
            ->assertJsonPath('code', 'MISSING_ORIGIN_DATA');
        Http::assertNothingSent();
    }
}
