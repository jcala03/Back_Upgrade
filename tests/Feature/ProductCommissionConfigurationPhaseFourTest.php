<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class ProductCommissionConfigurationPhaseFourTest extends TestCase
{
    use RefreshDatabase;

    public function createApplication()
    {
        $connection = trim((string) getenv('TEST_DB_CONNECTION'));
        $database = trim((string) getenv('TEST_DB_DATABASE'));

        if ($connection !== 'mysql' || $database === '') {
            throw new RuntimeException('ProductCommissionConfigurationPhaseFourTest requiere TEST_DB_CONNECTION=mysql y TEST_DB_DATABASE explícita.');
        }

        if (strcasecmp($database, 'upgrade') === 0) {
            throw new RuntimeException('ProductCommissionConfigurationPhaseFourTest no puede ejecutarse contra la base principal upgrade.');
        }

        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $app['config']->set('database.default', $connection);
        $app['config']->set("database.connections.{$connection}.database", $database);

        if (strcasecmp((string) $app['config']->get("database.connections.{$connection}.database"), 'upgrade') === 0) {
            throw new RuntimeException('La base configurada para ProductCommissionConfigurationPhaseFourTest no puede ser upgrade.');
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
    }

    public function test_product_commission_is_disabled_by_default(): void
    {
        $product = $this->product()->refresh();

        $this->assertFalse($product->commission_enabled);
        $this->assertNull($product->commission_amount);
        $this->assertFalse($product->generatesCommission());
        $this->assertNull($product->commissionAmount());
    }

    public function test_admin_can_create_disabled_product_and_response_exposes_configuration(): void
    {
        $response = $this->postJson('/api/admin/products', [
            'name' => 'Producto sin comisión',
            'is_visible' => false,
        ])->assertCreated()
            ->assertJsonStructure([
                'data' => ['commission_enabled', 'commission_amount'],
            ])
            ->assertJsonPath('data.commission_enabled', false)
            ->assertJsonPath('data.commission_amount', null);

        $this->assertDatabaseHas('products', [
            'id' => $response->json('data.id'),
            'commission_enabled' => false,
            'commission_amount' => null,
        ]);
    }

    public function test_admin_can_create_product_with_integer_commission(): void
    {
        $response = $this->postJson('/api/admin/products', [
            'name' => 'Pantalla comisionable',
            'is_visible' => false,
            'commission_enabled' => true,
            'commission_amount' => 50000,
        ])->assertCreated()
            ->assertJsonPath('data.commission_enabled', true)
            ->assertJsonPath('data.commission_amount', 50000);

        $product = Product::findOrFail($response->json('data.id'));

        $this->assertTrue($product->generatesCommission());
        $this->assertSame(50000, $product->commissionAmount());
    }

    public function test_enabled_product_requires_positive_commission_amount(): void
    {
        $this->postJson('/api/admin/products', [
            'name' => 'Sin tarifa',
            'commission_enabled' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('commission_amount');

        $this->postJson('/api/admin/products', [
            'name' => 'Tarifa cero',
            'commission_enabled' => true,
            'commission_amount' => 0,
        ])->assertUnprocessable()->assertJsonValidationErrors('commission_amount');

        $this->postJson('/api/admin/products', [
            'name' => 'Tarifa negativa',
            'commission_enabled' => true,
            'commission_amount' => -1,
        ])->assertUnprocessable()->assertJsonValidationErrors('commission_amount');

        $this->postJson('/api/admin/products', [
            'name' => 'Flag inválido',
            'commission_enabled' => 'not-a-boolean',
        ])->assertUnprocessable()->assertJsonValidationErrors('commission_enabled');
    }

    public function test_disabled_product_normalizes_commission_amount_to_null(): void
    {
        $response = $this->postJson('/api/admin/products', [
            'name' => 'Configuración deshabilitada',
            'is_visible' => false,
            'commission_enabled' => false,
            'commission_amount' => 50000,
        ])->assertCreated()
            ->assertJsonPath('data.commission_enabled', false)
            ->assertJsonPath('data.commission_amount', null);

        $this->assertDatabaseHas('products', [
            'id' => $response->json('data.id'),
            'commission_enabled' => false,
            'commission_amount' => null,
        ]);
    }

    public function test_admin_can_update_rate_and_disabling_normalizes_it(): void
    {
        $product = $this->product([
            'commission_enabled' => true,
            'commission_amount' => 50000,
        ]);

        $this->postJson("/api/admin/products/{$product->id}", [
            'commission_amount' => 60000,
        ])->assertOk()
            ->assertJsonPath('data.commission_enabled', true)
            ->assertJsonPath('data.commission_amount', 60000);

        $this->postJson("/api/admin/products/{$product->id}", [
            'commission_enabled' => false,
            'commission_amount' => 60000,
        ])->assertOk()
            ->assertJsonPath('data.commission_enabled', false)
            ->assertJsonPath('data.commission_amount', null);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'commission_enabled' => false,
            'commission_amount' => null,
        ]);
    }

    public function test_unrelated_update_preserves_enabled_commission_configuration(): void
    {
        $product = $this->product([
            'commission_enabled' => true,
            'commission_amount' => 50000,
        ]);

        $this->postJson("/api/admin/products/{$product->id}", [
            'description' => 'Descripción actualizada',
        ])->assertOk()
            ->assertJsonPath('data.commission_enabled', true)
            ->assertJsonPath('data.commission_amount', 50000);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'commission_enabled' => true,
            'commission_amount' => 50000,
        ]);
    }

    public function test_public_product_responses_do_not_expose_commission_configuration(): void
    {
        $product = $this->product([
            'commission_enabled' => true,
            'commission_amount' => 50000,
        ]);

        $this->getJson('/api/products')
            ->assertOk()
            ->assertJsonMissingPath('data.0.commission_enabled')
            ->assertJsonMissingPath('data.0.commission_amount');

        $this->getJson("/api/products/{$product->slug}")
            ->assertOk()
            ->assertJsonMissingPath('data.commission_enabled')
            ->assertJsonMissingPath('data.commission_amount');
    }

    public function test_admin_list_exposes_commission_configuration(): void
    {
        $product = $this->product([
            'commission_enabled' => true,
            'commission_amount' => 50000,
        ]);

        $this->getJson('/api/admin/products')
            ->assertOk()
            ->assertJsonPath('data.0.id', $product->id)
            ->assertJsonPath('data.0.commission_enabled', true)
            ->assertJsonPath('data.0.commission_amount', 50000);
    }

    public function test_variant_uses_parent_configuration_without_own_columns(): void
    {
        $product = $this->product([
            'commission_enabled' => true,
            'commission_amount' => 50000,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Versión 12.3',
            'normalized_name' => 'version 12.3',
            'sku' => 'VAR-COMMISSION-123',
            'price' => 100000,
        ]);

        $this->assertTrue($variant->product->generatesCommission());
        $this->assertSame(50000, $variant->product->commissionAmount());
        $this->assertFalse(Schema::hasColumn('product_variants', 'commission_enabled'));
        $this->assertFalse(Schema::hasColumn('product_variants', 'commission_amount'));
        $this->assertArrayNotHasKey('commission_enabled', $variant->getAttributes());
        $this->assertArrayNotHasKey('commission_amount', $variant->getAttributes());
    }

    private function product(array $overrides = []): Product
    {
        return Product::create(array_replace([
            'name' => 'Producto '.fake()->unique()->word(),
            'normalized_name' => fake()->word(),
            'slug' => fake()->unique()->slug(),
            'price' => 100000,
            'is_active' => true,
            'is_visible' => true,
            'main_image' => 'qa-ux/commission-fixture.png',
        ], $overrides));
    }
}
