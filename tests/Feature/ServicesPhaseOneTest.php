<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ServicesPhaseOneTest extends TestCase
{
    use RefreshDatabase;

    public function createApplication()
    {
        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        if ($connection = getenv('TEST_DB_CONNECTION')) {
            $app['config']->set('database.default', $connection);
            $app['config']->set("database.connections.{$connection}.database", getenv('TEST_DB_DATABASE') ?: 'upgrade_services_test');
        }

        return $app;
    }

    public function test_service_permissions_protect_catalog_and_sales_retains_read_access(): void
    {
        $this->getJson('/api/admin/services')->assertUnauthorized();
        $this->getJson('/api/admin/service-categories')->assertUnauthorized();

        $this->actingAsRole('user');
        $this->getJson('/api/admin/services')->assertForbidden();
        $this->getJson('/api/admin/service-categories')->assertForbidden();
        $this->postJson('/api/admin/services', [])->assertForbidden();
        $this->postJson('/api/admin/service-categories', [])->assertForbidden();

        $service = $this->service();
        $this->patchJson("/api/admin/services/{$service->id}", [])->assertForbidden();
        $this->deleteJson("/api/admin/services/{$service->id}")->assertForbidden();
        $this->patchJson("/api/admin/service-categories/{$service->service_category_id}", [])->assertForbidden();
        $this->deleteJson("/api/admin/service-categories/{$service->service_category_id}")->assertForbidden();
    }

    public function test_admin_can_manage_filter_and_sort_service_categories_without_physical_delete(): void
    {
        $this->actingAsRole('admin');
        $first = $this->postJson('/api/admin/service-categories', ['name' => 'Instalación', 'sort_order' => 20])
            ->assertCreated()->assertJsonPath('data.slug', 'instalacion')->json('data');
        $second = $this->postJson('/api/admin/service-categories', ['name' => 'Instalación', 'sort_order' => 10])
            ->assertCreated()->assertJsonPath('data.slug', 'instalacion-2')->json('data');

        $this->patchJson("/api/admin/service-categories/{$first['id']}", ['name' => 'Audio'])
            ->assertOk()->assertJsonPath('data.slug', 'audio');
        $this->getJson('/api/admin/service-categories?search=Audio&is_active=1&sort=name&direction=asc')
            ->assertOk()->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.name', 'Audio');
        $this->getJson('/api/admin/service-categories?sort=sort_order&direction=asc')
            ->assertJsonPath('data.data.0.id', $second['id']);

        $this->deleteJson("/api/admin/service-categories/{$first['id']}")
            ->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertDatabaseHas('service_categories', ['id' => $first['id'], 'is_active' => false]);
    }

    public function test_admin_service_crud_validation_filters_and_pagination(): void
    {
        $this->actingAsRole('admin');
        $category = $this->category();
        $payload = [
            'service_category_id' => $category->id,
            'name' => 'Instalación de pantalla',
            'description' => 'Configuración incluida',
            'price' => 250000,
            'cost' => null,
            'estimated_duration_minutes' => 180,
            'sort_order' => 5,
        ];
        $serviceId = $this->postJson('/api/admin/services', $payload)
            ->assertCreated()->assertJsonPath('data.price', 250000)->assertJsonPath('data.cost', null)->json('data.id');
        $this->getJson("/api/admin/services/{$serviceId}")->assertOk()->assertJsonPath('data.category.id', $category->id);
        $this->patchJson("/api/admin/services/{$serviceId}", ['price' => 275000, 'estimated_duration_minutes' => 200])
            ->assertOk()->assertJsonPath('data.price', 275000);
        $this->getJson("/api/admin/services?search=pantalla&service_category_id={$category->id}&is_active=1&sort=price&direction=desc&per_page=1")
            ->assertOk()->assertJsonPath('data.per_page', 1)->assertJsonCount(1, 'data.data');

        $this->postJson('/api/admin/services', [...$payload, 'name' => 'Inválido', 'estimated_duration_minutes' => 4])->assertUnprocessable();
        $this->postJson('/api/admin/services', [...$payload, 'name' => 'Inválido 2', 'estimated_duration_minutes' => 1441])->assertUnprocessable();
        $this->postJson('/api/admin/services', [...$payload, 'name' => 'Inválido 3', 'stock' => 2])->assertUnprocessable();

        $this->deleteJson("/api/admin/services/{$serviceId}")->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertDatabaseHas('services', ['id' => $serviceId, 'is_active' => false]);
    }

    public function test_legacy_product_payload_remains_product_and_moves_stock(): void
    {
        $this->actingAsRole('admin');
        $product = $this->product();

        $response = $this->postJson('/api/admin/orders', $this->commercialPayload([
            ['product_id' => $product->id, 'quantity' => 2],
        ]))
            ->assertCreated()->assertJsonPath('data.items.0.item_type', OrderItem::ITEM_TYPE_PRODUCT);

        $this->assertSame(3, $this->stock($product)->fresh()->quantity);
        $this->assertDatabaseHas('inventory_movements', [
            'branch_id' => $this->branch()->id,
            'product_id' => $product->id,
            'type' => InventoryMovement::TYPE_SALE,
            'quantity_delta' => -2,
        ]);
        $this->assertSame(200000, $response->json('data.total'));
    }

    public function test_service_only_order_uses_backend_snapshots_and_never_moves_inventory(): void
    {
        $this->actingAsRole('admin');
        $service = $this->service(price: 250000, cost: 90000);
        $response = $this->postJson('/api/admin/orders', $this->commercialPayload([[
            'item_type' => 'service', 'service_id' => $service->id, 'quantity' => 2, 'discount_amount' => 50000,
        ]]))->assertCreated();

        $response->assertJsonPath('data.items.0.item_type', 'service')
            ->assertJsonPath('data.items.0.service_name', $service->name)
            ->assertJsonPath('data.items.0.product_id', null)
            ->assertJsonPath('data.items.0.unit_price', 250000)
            ->assertJsonPath('data.items.0.unit_cost', 90000)
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.items.0.subtotal', 500000)
            ->assertJsonPath('data.items.0.discount_amount', 50000)
            ->assertJsonPath('data.total', 450000);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_service_null_cost_stays_null_and_ambiguous_payloads_are_rejected(): void
    {
        $this->actingAsRole('admin');
        $service = $this->service(cost: null);
        $product = $this->product();
        $this->postJson('/api/admin/orders', $this->commercialPayload([
            ['item_type' => 'service', 'service_id' => $service->id, 'quantity' => 1],
        ]))
            ->assertCreated()->assertJsonPath('data.items.0.unit_cost', null);
        $this->postJson('/api/admin/orders', $this->commercialPayload([
            ['service_id' => $service->id, 'quantity' => 1],
        ]))->assertUnprocessable();
        $this->postJson('/api/admin/orders', $this->commercialPayload([
            ['item_type' => 'service', 'service_id' => $service->id, 'product_id' => $product->id, 'quantity' => 1],
        ]))->assertUnprocessable();
        $this->postJson('/api/admin/orders', $this->commercialPayload([
            ['item_type' => 'product', 'product_id' => $product->id, 'service_id' => $service->id, 'quantity' => 1],
        ]))->assertUnprocessable();
    }

    public function test_mixed_order_moves_and_reverses_only_product_inventory(): void
    {
        $this->actingAsRole('admin');
        $product = $this->product();
        $service = $this->service(price: 150000);
        $orderId = $this->postJson('/api/admin/orders', $this->commercialPayload([
            ['product_id' => $product->id, 'quantity' => 1],
            ['item_type' => 'service', 'service_id' => $service->id, 'quantity' => 1],
        ]))->assertCreated()->assertJsonPath('data.total', 250000)->json('data.id');

        $this->assertSame(4, $this->stock($product)->fresh()->quantity);
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->postJson("/api/admin/orders/{$orderId}/status", ['status' => 'cancelled'])->assertOk();
        $this->assertSame(5, $this->stock($product)->fresh()->quantity);
        $this->assertDatabaseCount('inventory_movements', 2);
        $this->assertDatabaseHas('inventory_movements', ['type' => InventoryMovement::TYPE_SALE_REVERSAL, 'quantity_delta' => 1]);
    }

    public function test_inactive_service_or_category_is_not_commercially_available(): void
    {
        $this->actingAsRole('admin');
        $service = $this->service();
        $service->update(['is_active' => false]);
        $this->postJson('/api/admin/orders', $this->commercialPayload([
            ['item_type' => 'service', 'service_id' => $service->id, 'quantity' => 1],
        ]))->assertUnprocessable();
        $this->postJson('/api/admin/quotations', $this->commercialPayload([
            ['item_type' => 'service', 'service_id' => $service->id, 'quantity' => 1],
        ]))->assertUnprocessable();

        $service->update(['is_active' => true]);
        $service->category->update(['is_active' => false]);
        $this->postJson('/api/admin/orders', $this->commercialPayload([
            ['item_type' => 'service', 'service_id' => $service->id, 'quantity' => 1],
        ]))->assertUnprocessable();
        $this->postJson('/api/admin/quotations', $this->commercialPayload([
            ['item_type' => 'service', 'service_id' => $service->id, 'quantity' => 1],
        ]))->assertUnprocessable();
    }

    public function test_service_quotation_is_frozen_editable_and_does_not_move_inventory(): void
    {
        $this->actingAsRole('admin');
        $service = $this->service(price: 200000, cost: 70000);
        $quotationId = $this->postJson('/api/admin/quotations', $this->commercialPayload([[
            'item_type' => 'service', 'service_id' => $service->id, 'quantity' => 2, 'discount_amount' => 20000,
        ]]))->assertCreated()->assertJsonPath('data.total', 380000)->json('data.id');
        $this->assertDatabaseCount('inventory_movements', 0);

        $service->update(['name' => 'Nombre cambiado', 'price' => 999999]);
        $this->getJson("/api/admin/quotations/{$quotationId}")
            ->assertJsonPath('data.items.0.service_name', 'Instalación')
            ->assertJsonPath('data.items.0.unit_price', 200000);
        $this->patchJson("/api/admin/quotations/{$quotationId}", ['items' => [[
            'item_type' => 'service', 'service_id' => $service->id, 'quantity' => 1,
        ]]])->assertOk()->assertJsonPath('data.items.0.unit_price', 999999);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_mixed_quotation_conversion_preserves_frozen_service_price_and_revalidates_product_stock(): void
    {
        $this->actingAsRole('admin');
        $product = $this->product();
        $service = $this->service(price: 180000, cost: 60000);
        $quotationId = $this->postJson('/api/admin/quotations', $this->commercialPayload([
            ['product_id' => $product->id, 'quantity' => 1],
            ['item_type' => 'service', 'service_id' => $service->id, 'quantity' => 1, 'discount_amount' => 30000],
        ]))->assertCreated()->json('data.id');
        $service->update(['price' => 400000]);

        $converted = $this->postJson("/api/admin/quotations/{$quotationId}/convert")
            ->assertCreated()->assertJsonPath('data.status', Quotation::STATUS_CONVERTED);
        $order = Order::with('items')->findOrFail($converted->json('data.order_id'));
        $serviceItem = $order->items->firstWhere('item_type', 'service');
        $this->assertSame(180000, $serviceItem->unit_price);
        $this->assertSame(150000, $serviceItem->total);
        $this->assertSame(4, $this->stock($product)->fresh()->quantity);
        $this->assertDatabaseCount('inventory_movements', 1);
    }

    public function test_inactive_service_blocks_conversion_atomically(): void
    {
        $this->actingAsRole('admin');
        $service = $this->service();
        $quotationId = $this->postJson('/api/admin/quotations', $this->commercialPayload([[
            'item_type' => 'service', 'service_id' => $service->id, 'quantity' => 1,
        ]]))->assertCreated()->json('data.id');
        $service->update(['is_active' => false]);

        $this->postJson("/api/admin/quotations/{$quotationId}/convert")->assertUnprocessable();
        $this->assertDatabaseHas('quotations', ['id' => $quotationId, 'status' => Quotation::STATUS_DRAFT, 'order_id' => null]);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_dashboard_and_reports_include_service_revenue_but_exclude_it_from_products(): void
    {
        $this->actingAsRole('admin');
        $service = $this->service(price: 300000, cost: 100000);
        $this->postJson('/api/admin/orders', $this->commercialPayload([[
            'item_type' => 'service', 'service_id' => $service->id, 'quantity' => 1,
        ]]))->assertCreated();

        $this->getJson('/api/admin/dashboard')->assertOk()
            ->assertJsonPath('data.sales.month.total', 300000)
            ->assertJsonPath('data.financials.revenue_month', 300000)
            ->assertJsonPath('data.financials.cogs_month', 100000)
            ->assertJsonCount(0, 'data.top_products');
        $this->getJson('/api/admin/reports/sales')->assertOk()->assertJsonPath('data.summary.sales_total', 300000);
        $this->getJson('/api/admin/reports/products')->assertOk()
            ->assertJsonPath('data.summary.revenue', 0)->assertJsonCount(0, 'data.rows.data');
        $this->getJson('/api/admin/reports/inventory')->assertOk()->assertJsonCount(0, 'data.rows.data');
    }

    public function test_public_ecommerce_remains_product_only(): void
    {
        $service = $this->service();
        $this->withHeader('Idempotency-Key', __METHOD__)->postJson('/api/orders', [
            'customer_name' => 'Cliente', 'customer_email' => 'cliente@example.com', 'customer_phone' => '3000000000',
            'items' => [['item_type' => 'service', 'service_id' => $service->id, 'quantity' => 1]],
        ])->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function category(array $attributes = []): ServiceCategory
    {
        return ServiceCategory::create([
            'name' => $attributes['name'] ?? 'Instalación '.uniqid(),
            'slug' => $attributes['slug'] ?? 'instalacion-'.uniqid(),
            'is_active' => $attributes['is_active'] ?? true,
            'sort_order' => $attributes['sort_order'] ?? 0,
        ]);
    }

    private function service(int $price = 200000, ?int $cost = null): Service
    {
        return Service::create([
            'service_category_id' => $this->category()->id,
            'name' => 'Instalación',
            'slug' => 'instalacion-'.uniqid(),
            'description' => 'Trabajo especializado',
            'price' => $price,
            'cost' => $cost,
            'estimated_duration_minutes' => 120,
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    private function product(): Product
    {
        $product = Product::create([
            'name' => 'Pantalla',
            'slug' => 'pantalla-'.uniqid(),
            'sku' => 'SCR-'.uniqid(),
            'price' => 100000,
            'cost_price' => 40000,
            'is_active' => true,
            'is_visible' => true,
        ]);

        $item = InventoryItem::firstOrCreate([
            'product_id' => $product->id,
            'product_variant_id' => null,
        ]);
        InventoryStock::create([
            'branch_id' => $this->branch()->id,
            'inventory_item_id' => $item->id,
            'quantity' => 5,
            'minimum_quantity' => 1,
        ]);

        return $product;
    }

    private function commercialPayload(array $items): array
    {
        return [
            'branch_id' => $this->branch()->id,
            'items' => $items,
        ];
    }

    private function branch(): Branch
    {
        return Branch::firstOrCreate(
            ['code' => 'SERV'],
            ['slug' => 'servicios', 'name' => 'Sede servicios', 'city' => 'Barranquilla', 'is_active' => true],
        );
    }

    private function stock(Product $product): InventoryStock
    {
        return InventoryStock::query()
            ->where('branch_id', $this->branch()->id)
            ->whereHas('inventoryItem', fn ($query) => $query->where('product_id', $product->id))
            ->firstOrFail();
    }
}
