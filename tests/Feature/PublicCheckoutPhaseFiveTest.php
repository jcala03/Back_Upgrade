<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicCheckoutPhaseFiveTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_idempotent_public_order_has_hashed_token_and_safe_lookup(): void
    {
        $product = $this->product();
        $payload = $this->payload($product);
        $first = $this->withHeader('Idempotency-Key', 'checkout-key-1')->postJson('/api/orders', $payload)->assertCreated();
        $token = $first->json('public_token');
        $this->assertIsString($token);
        $order = Order::firstOrFail();
        $this->assertSame(hash('sha256', $token), $order->public_token_hash);
        $this->assertNotSame($token, $order->public_token_hash);
        $this->withHeader('Idempotency-Key', 'checkout-key-1')->postJson('/api/orders', $payload)->assertOk()->assertJsonPath('data.id', $order->id);
        $this->assertDatabaseCount('orders', 1);
        $this->withHeader('Idempotency-Key', 'checkout-key-1')->postJson('/api/orders', [...$payload, 'customer_name' => 'Otro cliente'])->assertUnprocessable()->assertJsonPath('code', 'IDEMPOTENCY_CONFLICT');
        $this->getJson('/api/checkout/orders/'.$token)->assertOk()->assertJsonMissingPath('data.branch_id')->assertJsonMissingPath('data.charges.0.metadata');
        $this->getJson('/api/checkout/orders/'.str_repeat('a', 64))->assertNotFound();
    }

    public function test_address_upsert_invalidates_delivery_charges_and_recalculates(): void
    {
        $product = $this->product();
        $response = $this->withHeader('Idempotency-Key', 'checkout-key-2')->postJson('/api/orders', $this->payload($product));
        $token = $response->json('public_token');
        $order = Order::firstOrFail();
        $order->charges()->create(['type' => 'shipping', 'label' => 'Envío', 'amount' => 10000, 'currency' => 'COP', 'metadata' => ['secret' => 'no']]);
        $order->update(['charges_total' => 10000, 'total' => 110000]);
        $this->putJson('/api/checkout/orders/'.$token.'/shipping-address', $this->address())->assertOk()->assertJsonPath('data.charges_total', 0)->assertJsonMissingPath('data.charges.0.metadata');
        $this->assertDatabaseCount('order_addresses', 1);
        $this->assertDatabaseMissing('order_charges', ['order_id' => $order->id, 'type' => 'shipping']);
    }

    private function product(): Product
    {
        return Product::create(['name' => 'Producto', 'slug' => 'producto-'.uniqid(), 'sku' => 'SKU-'.uniqid(), 'price' => 100000, 'is_active' => true, 'is_visible' => true]);
    }

    private function payload(Product $product): array
    {
        return ['customer_name' => 'Cliente Checkout', 'customer_email' => 'checkout@example.com', 'customer_phone' => '+573001234567', 'items' => [['product_id' => $product->id, 'quantity' => 1]]];
    }

    private function address(): array
    {
        return ['recipient_name' => 'Cliente Checkout', 'recipient_phone' => '+573001234567', 'country_code' => 'CO', 'state' => 'Atlántico', 'city' => 'Barranquilla', 'postal_code' => '080001', 'address_line1' => 'Calle 1'];
    }
}
