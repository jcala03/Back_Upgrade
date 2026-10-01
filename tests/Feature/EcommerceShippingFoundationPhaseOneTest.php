<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\OrderCharge;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\OrderTotalService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EcommerceShippingFoundationPhaseOneTest extends TestCase
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

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_order_charges_recalculate_backend_authoritative_total_and_default_currency(): void
    {
        $order = $this->order();

        $shipping = $order->charges()->create([
            'type' => OrderCharge::TYPE_SHIPPING,
            'label' => 'Envío nacional',
            'amount' => 20000,
            'metadata' => ['quote_reference' => 'manual-foundation'],
        ]);
        $order->charges()->create([
            'type' => OrderCharge::TYPE_INSURANCE,
            'label' => 'Seguro',
            'amount' => 5000,
        ]);

        $recalculated = app(OrderTotalService::class)->recalculate($order);

        $this->assertSame(OrderCharge::types(), ['shipping', 'insurance', 'handling', 'tax', 'duty']);
        $this->assertTrue($shipping->order->is($order));
        $this->assertSame('COP', $shipping->currency);
        $this->assertSame('COP', $recalculated->currency);
        $this->assertSame(Order::FULFILLMENT_PICKUP, $recalculated->fulfillment_type);
        $this->assertSame(25000, $recalculated->charges_total);
        $this->assertSame(115000, $recalculated->total);
    }

    public function test_order_shipping_address_is_an_immutable_order_snapshot_relation(): void
    {
        $order = $this->order();
        $address = $order->shippingAddress()->create([
            'recipient_name' => 'Cliente Internacional',
            'recipient_phone' => '+573001234567',
            'country_code' => 'CO',
            'state' => 'Atlántico',
            'city' => 'Barranquilla',
            'postal_code' => '080001',
            'address_line1' => 'Carrera 50 # 79-10',
            'address_line2' => 'Local 2',
            'delivery_notes' => 'Entregar en recepción',
        ]);

        $this->assertSame(OrderAddress::TYPE_SHIPPING, $address->type);
        $this->assertTrue($address->order->is($order));
        $this->assertTrue($order->fresh()->shippingAddress->is($address));
    }

    public function test_admin_branch_accepts_and_validates_logistics_origin_fields(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/admin/branches', [
            'code' => 'BAQ-E',
            'slug' => 'barranquilla-ecommerce',
            'name' => 'Barranquilla Ecommerce',
            'city' => 'Barranquilla',
            'ecommerce_priority' => 10,
            'country_code' => 'co',
            'state' => 'Atlántico',
            'postal_code' => '080001',
            'address_line1' => 'Dirección configurada de prueba',
        ])->assertCreated();

        $response->assertJsonPath('data.country_code', 'CO')
            ->assertJsonPath('data.ecommerce_priority', 10);
        $this->assertDatabaseHas('branches', [
            'code' => 'BAQ-E',
            'country_code' => 'CO',
            'ecommerce_priority' => 10,
        ]);

        $this->postJson('/api/admin/branches', [
            'code' => 'BAD', 'slug' => 'bad', 'name' => 'Bad', 'city' => 'Bogotá',
            'ecommerce_priority' => -1, 'country_code' => 'COL',
        ])->assertUnprocessable()->assertJsonValidationErrors(['ecommerce_priority', 'country_code']);
    }

    public function test_admin_product_logistics_support_variant_inheritance_and_overrides(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/admin/products', [
            'name' => 'Pantalla logística',
            'is_visible' => false,
            'compatibility_type' => Product::COMPATIBILITY_TYPE_UNIVERSAL,
            'requires_shipping' => true,
            'weight_grams' => 2500,
            'length_mm' => 400,
            'width_mm' => 250,
            'height_mm' => 120,
            'country_of_origin' => 'cn',
            'hs_code' => '8528.72',
            'customs_description' => 'Pantalla para vehículo',
            'variants' => [[
                'name' => 'Versión reforzada',
                'weight_grams' => 3000,
            ]],
        ])->assertCreated();

        $response->assertJsonPath('data.requires_shipping', true)
            ->assertJsonPath('data.country_of_origin', 'CN')
            ->assertJsonPath('data.variants.0.weight_grams', 3000)
            ->assertJsonPath('data.variants.0.effective_logistics.weight_grams', 3000)
            ->assertJsonPath('data.variants.0.effective_logistics.length_mm', 400)
            ->assertJsonPath('data.variants.0.effective_logistics.country_of_origin', 'CN');

        $variant = ProductVariant::with('product')->findOrFail($response->json('data.variants.0.id'));
        $this->assertSame(3000, $variant->resolvedLogistics()['weight_grams']);
        $this->assertSame(250, $variant->resolvedLogistics()['width_mm']);

        $this->postJson('/api/admin/products', [
            'name' => 'Logística inválida',
            'weight_grams' => 0,
            'country_of_origin' => 'COL',
        ])->assertUnprocessable()->assertJsonValidationErrors(['weight_grams', 'country_of_origin']);
    }

    public function test_public_checkout_rejects_totals_charges_currency_branch_and_fulfillment_manipulation(): void
    {
        $product = Product::create([
            'name' => 'Producto checkout',
            'slug' => 'producto-checkout',
            'sku' => 'CHECKOUT-1',
            'price' => 75000,
            'is_active' => true,
            'is_visible' => true,
        ]);
        $payload = $this->publicPayload($product);

        $this->withHeader('Idempotency-Key', __METHOD__.'-authoritative')->postJson('/api/orders', [
            ...$payload,
            'subtotal' => 1,
            'charges_total' => 1,
            'total' => 1,
            'currency' => 'USD',
            'branch_id' => 1,
            'fulfillment_type' => 'shipping',
            'charges' => [['type' => 'shipping', 'amount' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'subtotal', 'charges_total', 'total', 'currency', 'branch_id',
            'fulfillment_type', 'charges',
        ]);
        $this->assertDatabaseCount('orders', 0);

        $this->withHeader('Idempotency-Key', __METHOD__.'-valid')->postJson('/api/orders', $payload)
            ->assertCreated()
            ->assertJsonPath('data.subtotal', 150000)
            ->assertJsonPath('data.discount_total', 0)
            ->assertJsonPath('data.charges_total', 0)
            ->assertJsonPath('data.currency', 'COP')
            ->assertJsonPath('data.total', 150000);
    }

    private function order(): Order
    {
        return Order::create([
            'order_number' => 'SHIP-'.uniqid(),
            'customer_name' => 'Cliente',
            'subtotal' => 100000,
            'discount_total' => 10000,
            'total' => 90000,
            'status' => Order::STATUS_PENDING,
            'payment_status' => Order::PAYMENT_UNPAID,
        ]);
    }

    private function actingAsAdmin(): User
    {
        $user = User::factory()->create(['role' => User::ROLE_ADMIN]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function publicPayload(Product $product): array
    {
        return [
            'customer_name' => 'Cliente Checkout',
            'customer_email' => 'checkout@example.com',
            'customer_phone' => '+573001234567',
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ];
    }
}
