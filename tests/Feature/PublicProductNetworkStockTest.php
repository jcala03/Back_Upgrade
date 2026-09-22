<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class PublicProductNetworkStockTest extends TestCase
{
    use RefreshDatabase;

    public function createApplication()
    {
        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        $connection = (string) $app['config']->get('database.default');
        $database = (string) $app['config']->get("database.connections.{$connection}.database");

        if (strcasecmp($database, 'upgrade') === 0) {
            throw new RuntimeException('PublicProductNetworkStockTest no puede ejecutarse contra la base principal upgrade.');
        }

        return $app;
    }

    public function test_simple_product_sums_only_active_branch_stock_and_minimum(): void
    {
        $product = $this->product(['slug' => 'simple-red']);
        $baq = $this->branch('BAQ');
        $bog = $this->branch('BOG');
        $inactive = $this->branch('OFF', false);

        $this->stock($baq, $product, 5, 2);
        $this->stock($bog, $product, 2, 3);
        $this->stock($inactive, $product, 20, 100);

        $this->getJson('/api/products/simple-red')
            ->assertOk()
            ->assertJsonPath('data.stock', 7)
            ->assertJsonPath('data.stock_status', 'available')
            ->assertJsonPath('data.is_low_stock', false)
            ->assertJsonPath('data.total_variant_stock', 0)
            ->assertJsonMissingPath('data.minimum_stock')
            ->assertJsonMissingPath('data.initial_stock');
    }

    public function test_missing_inventory_item_or_position_is_out_of_stock(): void
    {
        $withoutItem = $this->product(['slug' => 'without-item']);
        $withItem = $this->product(['slug' => 'without-position']);
        InventoryItem::create([
            'product_id' => $withItem->id,
            'product_variant_id' => null,
        ]);

        $this->getJson('/api/products/without-item')
            ->assertOk()
            ->assertJsonPath('data.stock', 0)
            ->assertJsonPath('data.stock_status', 'out_of_stock')
            ->assertJsonPath('data.is_low_stock', false);

        $this->getJson('/api/products/without-position')
            ->assertOk()
            ->assertJsonPath('data.stock', 0)
            ->assertJsonPath('data.stock_status', 'out_of_stock')
            ->assertJsonPath('data.is_low_stock', false);

        $this->assertSame(0, $withoutItem->inventoryItem()->count());
    }

    public function test_variant_and_product_use_network_aggregates_without_parent_fallback(): void
    {
        $product = $this->product(['slug' => 'with-variants']);
        $variantA = $this->variant($product, 'A', ['sort_order' => 1]);
        $variantB = $this->variant($product, 'B', ['sort_order' => 2]);
        $baq = $this->branch('BAQ');
        $bog = $this->branch('BOG');

        $this->stock($baq, $product, 50, 50);
        $this->stock($baq, $product, 2, 3, $variantA);
        $this->stock($bog, $product, 1, 0, $variantA);
        $this->stock($baq, $product, 5, 1, $variantB);

        $response = $this->getJson('/api/products/with-variants')
            ->assertOk()
            ->assertJsonPath('data.stock', 8)
            ->assertJsonPath('data.total_variant_stock', 8)
            ->assertJsonPath('data.stock_status', 'available')
            ->assertJsonPath('data.is_low_stock', false);

        $variants = collect($response->json('data.variants'))->keyBy('id');
        $this->assertSame(3, $variants[$variantA->id]['stock']);
        $this->assertSame('low_stock', $variants[$variantA->id]['stock_status']);
        $this->assertTrue($variants[$variantA->id]['is_low_stock']);
        $this->assertSame(5, $variants[$variantB->id]['stock']);
    }

    public function test_inactive_and_invisible_variants_do_not_contribute_to_product_stock_or_minimum(): void
    {
        $product = $this->product(['slug' => 'eligible-variants']);
        $active = $this->variant($product, 'Activa');
        $inactive = $this->variant($product, 'Inactiva', ['is_active' => false]);
        $hidden = $this->variant($product, 'Oculta', ['is_visible' => false]);
        $branch = $this->branch('BAQ');

        $this->stock($branch, $product, 5, 1, $active);
        $this->stock($branch, $product, 10, 100, $inactive);
        $this->stock($branch, $product, 10, 100, $hidden);

        $this->getJson('/api/products/eligible-variants')
            ->assertOk()
            ->assertJsonPath('data.stock', 5)
            ->assertJsonPath('data.stock_status', 'available')
            ->assertJsonPath('data.is_low_stock', false)
            ->assertJsonCount(1, 'data.variants')
            ->assertJsonPath('data.variants.0.id', $active->id);
    }

    public function test_eligible_zero_stock_variant_never_falls_back_to_parent_and_is_not_in_stock(): void
    {
        $product = $this->product(['slug' => 'zero-variant']);
        $variant = $this->variant($product, 'Sin existencias');
        $branch = $this->branch('BAQ');

        $this->stock($branch, $product, 50, 0);
        $this->stock($branch, $product, 0, 0, $variant);

        $this->getJson('/api/products/zero-variant')
            ->assertOk()
            ->assertJsonPath('data.stock', 0)
            ->assertJsonPath('data.total_variant_stock', 0)
            ->assertJsonPath('data.stock_status', 'out_of_stock');

        $this->getJson('/api/products?in_stock=true')
            ->assertOk()
            ->assertJsonMissing(['id' => $product->id]);
    }

    public function test_public_stock_status_uses_aggregated_minimum_and_excludes_inactive_branches(): void
    {
        $available = $this->product(['slug' => 'available']);
        $low = $this->product(['slug' => 'low']);
        $out = $this->product(['slug' => 'out']);
        $inactiveMinimum = $this->product(['slug' => 'inactive-minimum']);
        $active = $this->branch('BAQ');
        $secondActive = $this->branch('BOG');
        $inactive = $this->branch('OFF', false);

        $this->stock($active, $available, 10, 5);
        $this->stock($active, $low, 3, 2);
        $this->stock($secondActive, $low, 1, 3);
        $this->stock($active, $out, 0, 5);
        $this->stock($active, $inactiveMinimum, 5, 2);
        $this->stock($inactive, $inactiveMinimum, 0, 100);

        $this->getJson('/api/products/available')
            ->assertOk()
            ->assertJsonPath('data.stock_status', 'available')
            ->assertJsonPath('data.is_low_stock', false);
        $this->getJson('/api/products/low')
            ->assertOk()
            ->assertJsonPath('data.stock', 4)
            ->assertJsonPath('data.stock_status', 'low_stock')
            ->assertJsonPath('data.is_low_stock', true);
        $this->getJson('/api/products/out')
            ->assertOk()
            ->assertJsonPath('data.stock_status', 'out_of_stock')
            ->assertJsonPath('data.is_low_stock', false);
        $this->getJson('/api/products/inactive-minimum')
            ->assertOk()
            ->assertJsonPath('data.stock', 5)
            ->assertJsonPath('data.stock_status', 'available')
            ->assertJsonPath('data.is_low_stock', false);
    }

    public function test_product_variant_minimum_uses_only_eligible_variants(): void
    {
        $product = $this->product(['slug' => 'variant-minimum']);
        $lowA = $this->variant($product, 'A');
        $lowB = $this->variant($product, 'B');
        $inactive = $this->variant($product, 'Inactiva', ['is_active' => false]);
        $branch = $this->branch('BAQ');

        $this->stock($branch, $product, 2, 2, $lowA);
        $this->stock($branch, $product, 1, 2, $lowB);
        $this->stock($branch, $product, 0, 100, $inactive);

        $this->getJson('/api/products/variant-minimum')
            ->assertOk()
            ->assertJsonPath('data.stock', 3)
            ->assertJsonPath('data.stock_status', 'low_stock')
            ->assertJsonPath('data.is_low_stock', true);
    }

    public function test_in_stock_filter_uses_network_stock_for_simple_products(): void
    {
        $inStock = $this->product(['slug' => 'simple-in-stock']);
        $outOfStock = $this->product(['slug' => 'simple-out-stock']);
        $branch = $this->branch('BAQ');
        $this->stock($branch, $inStock, 1, 0);
        $this->stock($branch, $outOfStock, 0, 0);

        $ids = collect($this->getJson('/api/products?in_stock=true')
            ->assertOk()
            ->json('data'))
            ->pluck('id');

        $this->assertTrue($ids->contains($inStock->id));
        $this->assertFalse($ids->contains($outOfStock->id));
    }

    public function test_stock_sort_uses_the_public_stock_expression(): void
    {
        $ten = $this->product(['slug' => 'stock-ten']);
        $five = $this->product(['slug' => 'stock-five']);
        $zero = $this->product(['slug' => 'stock-zero']);
        $variantProduct = $this->product(['slug' => 'stock-variants']);
        $variant = $this->variant($variantProduct, 'Única');
        $branch = $this->branch('BAQ');

        $this->stock($branch, $ten, 10, 0);
        $this->stock($branch, $five, 5, 0);
        $this->stock($branch, $variantProduct, 100, 0);
        $this->stock($branch, $variantProduct, 2, 0, $variant);

        $data = $this->getJson('/api/products?sort=stock')
            ->assertOk()
            ->json('data');
        $ordered = collect($data)->keyBy('id');
        $ids = collect($data)->pluck('id')->all();

        $this->assertSame(10, $ordered[$ten->id]['stock']);
        $this->assertSame(5, $ordered[$five->id]['stock']);
        $this->assertSame(2, $ordered[$variantProduct->id]['stock']);
        $this->assertSame(0, $ordered[$zero->id]['stock']);
        $this->assertLessThan(array_search($five->id, $ids, true), array_search($ten->id, $ids, true));
        $this->assertLessThan(array_search($variantProduct->id, $ids, true), array_search($five->id, $ids, true));
        $this->assertLessThan(array_search($zero->id, $ids, true), array_search($variantProduct->id, $ids, true));
    }

    public function test_public_list_and_detail_do_not_create_inventory_records(): void
    {
        $product = $this->product(['slug' => 'read-only']);
        $itemsBefore = InventoryItem::count();
        $stocksBefore = InventoryStock::count();
        $movementsBefore = InventoryMovement::count();

        $this->getJson('/api/products')->assertOk();
        $this->getJson("/api/products/{$product->slug}")->assertOk();

        $this->assertSame($itemsBefore, InventoryItem::count());
        $this->assertSame($stocksBefore, InventoryStock::count());
        $this->assertSame($movementsBefore, InventoryMovement::count());
    }

    private function branch(string $prefix, bool $active = true): Branch
    {
        $unique = substr(str_replace('.', '', uniqid('', true)), -6);
        $code = substr($prefix.$unique, 0, 12);

        return Branch::create([
            'code' => $code,
            'slug' => strtolower($prefix).'-'.$unique,
            'name' => "Sede {$prefix}",
            'city' => 'Barranquilla',
            'is_active' => $active,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function product(array $overrides = []): Product
    {
        $unique = substr(str_replace('.', '', uniqid('', true)), -8);

        return Product::create(array_replace([
            'name' => "Producto {$unique}",
            'normalized_name' => "producto {$unique}",
            'slug' => "producto-{$unique}",
            'sku' => "SKU-{$unique}",
            'price' => 100000,
            'cost_price' => 30000,
            'tax_amount' => 5000,
            'extra_charges' => 2000,
            'total_cost' => 37000,
            'profit_amount' => 63000,
            'profit_margin_percent' => 63,
            'markup_percent' => 170.27,
            'pricing_mode' => Product::PRICING_MODE_MANUAL,
            'is_active' => true,
            'is_visible' => true,
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function variant(Product $product, string $name, array $overrides = []): ProductVariant
    {
        $unique = substr(str_replace('.', '', uniqid('', true)), -8);

        return ProductVariant::create(array_replace([
            'product_id' => $product->id,
            'name' => $name,
            'normalized_name' => strtolower($name),
            'sku' => "VAR-{$unique}",
            'price' => 120000,
            'cost_price' => 40000,
            'tax_amount' => 6000,
            'extra_charges' => 2000,
            'total_cost' => 48000,
            'profit_amount' => 72000,
            'profit_margin_percent' => 60,
            'markup_percent' => 150,
            'pricing_mode' => Product::PRICING_MODE_MANUAL,
            'is_default' => false,
            'is_active' => true,
            'is_visible' => true,
            'sort_order' => 0,
        ], $overrides));
    }

    private function stock(
        Branch $branch,
        Product $product,
        int $quantity,
        int $minimum,
        ?ProductVariant $variant = null,
    ): InventoryStock {
        $item = InventoryItem::firstOrCreate($variant
            ? ['product_id' => null, 'product_variant_id' => $variant->id]
            : ['product_id' => $product->id, 'product_variant_id' => null]);

        return InventoryStock::create([
            'branch_id' => $branch->id,
            'inventory_item_id' => $item->id,
            'quantity' => $quantity,
            'minimum_quantity' => $minimum,
        ]);
    }
}
