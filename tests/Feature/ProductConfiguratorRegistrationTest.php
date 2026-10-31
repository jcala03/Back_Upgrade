<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Services\ProductConfiguratorRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductConfiguratorRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function product(): Product
    {
        return Product::create(['name' => 'Fixture de configuración', 'slug' => 'configuration-fixture', 'price' => 1000000,
            'is_active' => true, 'is_visible' => true, 'compatibility_type' => 'universal'])->fresh();
    }

    private function enroll(): void
    {
        $product = Product::firstOrFail();
        config(['product_configurator.catalog' => app(ProductConfiguratorRegistry::class)->catalogIdentity($product),
            'product_configurator.products' => [$product->id => [
                'profile_id' => 'vehicle-oem-memory-v1', 'profile_version' => 1,
            ]]]);
    }

    public function test_default_registry_is_empty_and_existing_products_remain_traditional(): void
    {
        $product = $this->product();
        $this->assertSame([], config('product_configurator.products'));
        $this->assertFalse(config('product_configurator.preview_enabled'));
        $this->getJson('/api/products/'.$product->slug)->assertOk()->assertJsonPath('data.configuration', null);
        $this->getJson('/api/products')->assertOk()->assertJsonPath('data.0.configuration', null);
    }

    public function test_explicit_enrollment_is_versioned_additive_and_preview_off_by_default(): void
    {
        $product = $this->product();
        $this->enroll();
        $expected = ['schema_version' => 1, 'required' => true, 'profile_id' => 'vehicle-oem-memory-v1',
            'profile_version' => 1, 'ui_enabled' => false, 'purchase_enabled' => false];
        $this->getJson('/api/products/'.$product->slug)->assertOk()->assertJsonPath('data.configuration', $expected);
        config(['product_configurator.preview_enabled' => true]);
        $expected['ui_enabled'] = true;
        $this->getJson('/api/products')->assertOk()->assertJsonPath('data.0.configuration', $expected)
            ->assertJsonMissingPath('data.0.cost_price')->assertJsonMissingPath('data.0.commission_amount');
        $this->assertSame($product->getAttributes(), $product->fresh()->getAttributes());
        $this->assertDatabaseCount('product_variants', 0);
        $this->assertDatabaseCount('inventory_items', 0);
    }

    public function test_unknown_or_malformed_profiles_remain_required_without_enabling_buying(): void
    {
        $product = $this->product();
        config(['product_configurator.preview_enabled' => true]);
        foreach ([null, [], ['profile_id' => 'unknown', 'profile_version' => 1], ['profile_id' => 'vehicle-oem-memory-v1', 'profile_version' => 2]] as $entry) {
            config(['product_configurator.catalog' => app(ProductConfiguratorRegistry::class)->catalogIdentity($product),
                'product_configurator.products' => [$product->id => $entry]]);
            $this->getJson('/api/products/'.$product->slug)->assertOk()
                ->assertJsonPath('data.configuration.required', true)
                ->assertJsonPath('data.configuration.ui_enabled', false)
                ->assertJsonPath('data.configuration.purchase_enabled', false);
        }
    }

    public function test_production_cannot_enable_preview_or_buying_even_with_config_flags(): void
    {
        $product = $this->product();
        $this->enroll();
        config(['product_configurator.preview_enabled' => true, 'product_configurator.purchase_enabled' => true]);
        app()->instance('env', 'production');
        try {
            $result = app(ProductConfiguratorRegistry::class)->registration($product);
            $this->assertTrue($result['required']);
            $this->assertFalse($result['ui_enabled']);
            $this->assertFalse($result['purchase_enabled']);
        } finally {
            app()->instance('env', 'testing');
        }
    }

    public function test_matching_requires_stable_id_and_exact_catalog_identity_not_a_slug(): void
    {
        $product = $this->product();
        config(['product_configurator.products' => [$product->slug => ['profile_id' => 'vehicle-oem-memory-v1', 'profile_version' => 1]]]);
        $this->assertNull(app(ProductConfiguratorRegistry::class)->registration($product));
        $this->enroll();
        config(['product_configurator.preview_enabled' => true, 'product_configurator.purchase_enabled' => true]);
        $product->update(['name' => 'Renombrado', 'slug' => 'renombrado']);
        $this->assertTrue(app(ProductConfiguratorRegistry::class)->registration($product)['purchase_enabled']);
        config(['product_configurator.catalog.database' => 'different_catalog']);
        $this->assertFalse(app(ProductConfiguratorRegistry::class)->registration($product)['purchase_enabled']);
        $other = Product::create(['name' => $product->name, 'slug' => 'another-public-product', 'price' => $product->price, 'is_active' => true]);
        $this->assertNull(app(ProductConfiguratorRegistry::class)->registration($other));
    }
}
