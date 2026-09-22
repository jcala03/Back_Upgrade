<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CrmNotification;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\CrmNotificationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class MultibranchInventoryPhaseTwoTest extends TestCase
{
    use RefreshDatabase;

    public function createApplication()
    {
        $connection = trim((string) getenv('TEST_DB_CONNECTION'));
        $database = trim((string) getenv('TEST_DB_DATABASE'));

        if ($connection !== 'mysql' || $database === '') {
            throw new RuntimeException('MultibranchInventoryPhaseTwoTest requiere TEST_DB_CONNECTION=mysql y TEST_DB_DATABASE explícita.');
        }

        if (strcasecmp($database, 'upgrade') === 0) {
            throw new RuntimeException('MultibranchInventoryPhaseTwoTest no puede ejecutarse contra la base principal upgrade.');
        }

        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $app['config']->set('database.default', $connection);
        $app['config']->set("database.connections.{$connection}.database", $database);

        if (strcasecmp((string) $app['config']->get("database.connections.{$connection}.database"), 'upgrade') === 0) {
            throw new RuntimeException('La base configurada para MultibranchInventoryPhaseTwoTest no puede ser upgrade.');
        }

        return $app;
    }

    public function test_inventory_endpoints_require_authentication(): void
    {
        $branch = $this->branch();
        $product = $this->product();

        $this->getJson('/api/admin/inventory/stocks')->assertUnauthorized();
        $this->postJson('/api/admin/inventory/movements', $this->movementPayload($branch, $product))->assertUnauthorized();
        $this->patchJson('/api/admin/inventory/stocks/minimum', $this->minimumPayload($branch, $product, 2))->assertUnauthorized();
    }

    public function test_base_user_cannot_view_or_mutate_multibranch_inventory(): void
    {
        $branch = $this->branch();
        $product = $this->product();
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_USER, 'is_active' => true]));

        $this->getJson('/api/admin/inventory/stocks')->assertForbidden();
        $this->postJson('/api/admin/inventory/movements', $this->movementPayload($branch, $product))->assertForbidden();
        $this->patchJson('/api/admin/inventory/stocks/minimum', $this->minimumPayload($branch, $product, 2))->assertForbidden();
    }

    public function test_admin_keeps_existing_inventory_permissions_and_user_does_not_receive_them(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
        $user = User::factory()->create(['role' => User::ROLE_USER, 'is_active' => true]);

        $this->assertTrue($admin->hasPermission('inventory.view'));
        $this->assertTrue($admin->hasPermission('inventory.update'));
        $this->assertFalse($user->hasPermission('inventory.view'));
        $this->assertFalse($user->hasPermission('inventory.update'));
    }

    public function test_product_inventory_item_is_resolved_once_across_repeated_movements(): void
    {
        $this->admin();
        $branch = $this->branch();
        $product = $this->product();

        $this->postJson('/api/admin/inventory/movements', $this->movementPayload($branch, $product, quantity: 4))
            ->assertCreated()
            ->assertJsonPath('data.branch_id', $branch->id)
            ->assertJsonPath('data.product_id', $product->id)
            ->assertJsonPath('data.product_variant_id', null)
            ->assertJsonPath('data.stock_before', 0)
            ->assertJsonPath('data.stock_after', 4);
        $this->postJson('/api/admin/inventory/movements', $this->movementPayload($branch, $product, quantity: 2))
            ->assertCreated();

        $this->assertDatabaseCount('inventory_items', 1);
        $this->assertDatabaseHas('inventory_items', [
            'product_id' => $product->id,
            'product_variant_id' => null,
        ]);
        $this->assertDatabaseCount('inventory_stocks', 1);
        $this->assertDatabaseHas('inventory_stocks', [
            'branch_id' => $branch->id,
            'quantity' => 6,
        ]);
    }

    public function test_variant_inventory_item_is_independent_and_must_belong_to_product(): void
    {
        $this->admin();
        $branch = $this->branch();
        $product = $this->product();
        $variant = $this->variant($product, '12 pulgadas');
        $otherProduct = $this->product();

        $this->postJson('/api/admin/inventory/movements', $this->movementPayload($branch, $product, variant: $variant, quantity: 3))
            ->assertCreated()
            ->assertJsonPath('data.product_variant_id', $variant->id);

        $this->assertDatabaseHas('inventory_items', [
            'product_id' => null,
            'product_variant_id' => $variant->id,
        ]);

        $this->postJson('/api/admin/inventory/movements', $this->movementPayload($branch, $otherProduct, variant: $variant))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('product_variant_id');
    }

    public function test_product_with_variants_requires_an_explicit_variant(): void
    {
        $this->admin();
        $branch = $this->branch();
        $product = $this->product();
        $this->variant($product, '8 pulgadas');

        $this->postJson('/api/admin/inventory/movements', $this->movementPayload($branch, $product))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('product_variant_id');

        $this->assertDatabaseCount('inventory_items', 0);
        $this->assertDatabaseCount('inventory_stocks', 0);
    }

    public function test_inventory_item_database_constraint_requires_exactly_one_catalog_reference(): void
    {
        $this->expectException(QueryException::class);

        DB::table('inventory_items')->insert([
            'product_id' => null,
            'product_variant_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_inventory_item_database_constraint_rejects_both_catalog_references(): void
    {
        $product = $this->product();
        $variant = $this->variant($product, 'Referencia inválida');

        $this->expectException(QueryException::class);

        DB::table('inventory_items')->insert([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_inventory_item_unique_constraint_prevents_duplicate_product_items(): void
    {
        $product = $this->product();
        InventoryItem::create(['product_id' => $product->id]);

        $this->expectException(QueryException::class);
        InventoryItem::create(['product_id' => $product->id]);
    }

    public function test_inventory_stock_unique_constraint_prevents_duplicate_branch_item_rows(): void
    {
        $branch = $this->branch();
        $product = $this->product();
        $item = InventoryItem::create(['product_id' => $product->id]);
        InventoryStock::create([
            'branch_id' => $branch->id,
            'inventory_item_id' => $item->id,
            'quantity' => 0,
            'minimum_quantity' => 0,
        ]);

        $this->expectException(QueryException::class);
        InventoryStock::create([
            'branch_id' => $branch->id,
            'inventory_item_id' => $item->id,
            'quantity' => 1,
            'minimum_quantity' => 0,
        ]);
    }

    public function test_stock_is_independent_between_branches(): void
    {
        $this->admin();
        $barranquilla = $this->branch();
        $bogota = $this->branch([
            'code' => 'BOG',
            'slug' => 'bogota',
            'name' => 'Bogotá',
            'city' => 'Bogotá',
        ]);
        $product = $this->product();

        $this->postJson('/api/admin/inventory/movements', $this->movementPayload($barranquilla, $product, quantity: 8))->assertCreated();
        $this->postJson('/api/admin/inventory/movements', $this->movementPayload($bogota, $product, quantity: 3))->assertCreated();

        $item = InventoryItem::where('product_id', $product->id)->firstOrFail();
        $this->assertDatabaseHas('inventory_stocks', [
            'branch_id' => $barranquilla->id,
            'inventory_item_id' => $item->id,
            'quantity' => 8,
        ]);
        $this->assertDatabaseHas('inventory_stocks', [
            'branch_id' => $bogota->id,
            'inventory_item_id' => $item->id,
            'quantity' => 3,
        ]);
        $this->assertDatabaseCount('inventory_stocks', 2);
    }

    public function test_exit_rejects_negative_stock_and_rolls_back_movement(): void
    {
        $this->admin();
        $branch = $this->branch();
        $product = $this->product();
        $this->postJson('/api/admin/inventory/movements', $this->movementPayload($branch, $product, quantity: 2))->assertCreated();
        $beforeCount = InventoryMovement::count();

        $this->postJson('/api/admin/inventory/movements', $this->movementPayload($branch, $product, type: 'exit', quantity: 3))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quantity');

        $this->assertSame($beforeCount, InventoryMovement::count());
        $this->assertDatabaseHas('inventory_stocks', ['branch_id' => $branch->id, 'quantity' => 2]);
    }

    public function test_adjustment_preserves_target_quantity_semantics(): void
    {
        $this->admin();
        $branch = $this->branch();
        $product = $this->product();
        $this->postJson('/api/admin/inventory/movements', $this->movementPayload($branch, $product, quantity: 5))->assertCreated();

        $this->postJson('/api/admin/inventory/movements', [
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'type' => 'adjustment',
            'new_stock' => 2,
            'reason' => 'Conteo físico',
        ])->assertCreated()
            ->assertJsonPath('data.quantity_delta', -3)
            ->assertJsonPath('data.stock_before', 5)
            ->assertJsonPath('data.stock_after', 2);

        $this->assertDatabaseHas('inventory_stocks', ['branch_id' => $branch->id, 'quantity' => 2]);
    }

    public function test_minimum_quantity_is_branch_scoped_and_does_not_create_a_movement(): void
    {
        $this->admin();
        $barranquilla = $this->branch();
        $bogota = $this->branch([
            'code' => 'BOG',
            'slug' => 'bogota',
            'name' => 'Bogotá',
            'city' => 'Bogotá',
        ]);
        $product = $this->product();

        $this->patchJson('/api/admin/inventory/stocks/minimum', $this->minimumPayload($barranquilla, $product, 3))
            ->assertOk()
            ->assertJsonPath('data.minimum_quantity', 3);
        $this->patchJson('/api/admin/inventory/stocks/minimum', $this->minimumPayload($bogota, $product, 1))
            ->assertOk()
            ->assertJsonPath('data.minimum_quantity', 1);

        $this->assertDatabaseHas('inventory_stocks', ['branch_id' => $barranquilla->id, 'minimum_quantity' => 3]);
        $this->assertDatabaseHas('inventory_stocks', ['branch_id' => $bogota->id, 'minimum_quantity' => 1]);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_inactive_branch_cannot_receive_movements_or_minimum_updates(): void
    {
        $this->admin();
        $branch = $this->branch(['is_active' => false]);
        $product = $this->product();

        $this->postJson('/api/admin/inventory/movements', $this->movementPayload($branch, $product))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('branch_id');
        $this->patchJson('/api/admin/inventory/stocks/minimum', $this->minimumPayload($branch, $product, 2))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('branch_id');

        $this->assertDatabaseCount('inventory_items', 0);
        $this->assertDatabaseCount('inventory_stocks', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_manual_endpoint_rejects_domain_only_movement_types(): void
    {
        $this->admin();
        $branch = $this->branch();
        $product = $this->product();

        foreach (['sale', 'sale_reversal', 'transfer_out', 'transfer_in'] as $type) {
            $this->postJson('/api/admin/inventory/movements', $this->movementPayload($branch, $product, type: $type))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('type');
        }

        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_stock_listing_supports_branch_product_search_variant_and_low_stock_filters(): void
    {
        $this->admin();
        $branch = $this->branch();
        $otherBranch = $this->branch([
            'code' => 'BOG',
            'slug' => 'bogota',
            'name' => 'Bogotá',
            'city' => 'Bogotá',
        ]);
        $product = $this->product(sku: 'SCREEN-BAQ');
        $variantProduct = $this->product(sku: 'VARIANT-PARENT');
        $variant = $this->variant($variantProduct, '12.3', sku: 'SCREEN-123');

        $this->postJson('/api/admin/inventory/movements', $this->movementPayload($branch, $product, quantity: 2))->assertCreated();
        $this->patchJson('/api/admin/inventory/stocks/minimum', $this->minimumPayload($branch, $product, 3))->assertOk();
        $this->postJson('/api/admin/inventory/movements', $this->movementPayload($branch, $variantProduct, variant: $variant, quantity: 9))->assertCreated();
        $this->postJson('/api/admin/inventory/movements', $this->movementPayload($otherBranch, $product, quantity: 8))->assertCreated();

        $this->getJson("/api/admin/inventory/stocks?branch_id={$branch->id}&product_id={$product->id}&low_stock=1&search=SCREEN-BAQ")
            ->assertOk()
            ->assertJsonFragment(['product_id' => $product->id])
            ->assertJsonFragment(['quantity' => 2, 'minimum_quantity' => 3, 'is_low_stock' => true])
            ->assertJsonMissing(['product_variant_id' => $variant->id]);

        $this->getJson("/api/admin/inventory/stocks?product_variant_id={$variant->id}")
            ->assertOk()
            ->assertJsonFragment(['product_variant_id' => $variant->id, 'quantity' => 9]);
    }

    public function test_branch_movements_store_item_branch_and_coherent_catalog_references(): void
    {
        $this->admin();
        $branch = $this->branch();
        $product = $this->product();
        $variant = $this->variant($product, 'Premium');

        $movementId = $this->postJson('/api/admin/inventory/movements', $this->movementPayload($branch, $product, variant: $variant, quantity: 4))
            ->assertCreated()
            ->json('data.id');
        $item = InventoryItem::where('product_variant_id', $variant->id)->firstOrFail();

        $this->assertDatabaseHas('inventory_movements', [
            'id' => $movementId,
            'branch_id' => $branch->id,
            'inventory_item_id' => $item->id,
            'inventory_transfer_item_id' => null,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'type' => 'entry',
            'quantity_delta' => 4,
            'stock_before' => 0,
            'stock_after' => 4,
        ]);
    }

    public function test_branch_stock_low_and_out_notifications_include_branch_context_without_noise(): void
    {
        $admin = $this->admin();
        $branch = $this->branch();
        $product = $this->product();
        $this->postJson('/api/admin/inventory/movements', $this->movementPayload($branch, $product, quantity: 5))->assertCreated();
        $this->patchJson('/api/admin/inventory/stocks/minimum', $this->minimumPayload($branch, $product, 2))->assertOk();

        $this->postJson('/api/admin/inventory/movements', $this->movementPayload($branch, $product, type: 'exit', quantity: 3))->assertCreated();
        $this->postJson('/api/admin/inventory/movements', $this->movementPayload($branch, $product, type: 'exit', quantity: 2))->assertCreated();

        $notifications = CrmNotification::where('user_id', $admin->id)->orderBy('id')->get();
        $this->assertCount(2, $notifications);
        $this->assertSame(CrmNotification::TYPE_STOCK_LOW, $notifications[0]->type);
        $this->assertSame(CrmNotification::TYPE_STOCK_OUT, $notifications[1]->type);
        $this->assertSame($branch->id, $notifications[0]->data['branch_id']);
        $this->assertStringContainsString($branch->code, $notifications[0]->message);
    }

    public function test_notification_failure_does_not_rollback_branch_stock_core(): void
    {
        $this->mock(CrmNotificationService::class, function (MockInterface $mock) {
            $mock->shouldReceive('distribute')->andThrow(new RuntimeException('notification unavailable'));
        });
        $this->admin();
        $branch = $this->branch();
        $product = $this->product();
        $this->postJson('/api/admin/inventory/movements', $this->movementPayload($branch, $product, quantity: 3))->assertCreated();
        $this->patchJson('/api/admin/inventory/stocks/minimum', $this->minimumPayload($branch, $product, 2))->assertOk();

        $this->postJson('/api/admin/inventory/movements', $this->movementPayload($branch, $product, type: 'exit', quantity: 1))->assertCreated();

        $this->assertDatabaseHas('inventory_stocks', ['branch_id' => $branch->id, 'quantity' => 2]);
        $this->assertDatabaseHas('inventory_movements', ['branch_id' => $branch->id, 'stock_after' => 2]);
    }

    public function test_product_with_inventory_history_cannot_be_physically_deleted(): void
    {
        $this->admin();
        $branch = $this->branch();
        $product = $this->product();
        $this->postJson('/api/admin/inventory/movements', $this->movementPayload($branch, $product))->assertCreated();

        $this->deleteJson("/api/admin/products/{$product->id}")
            ->assertStatus(409);

        $this->assertDatabaseHas('products', ['id' => $product->id]);
        $this->assertDatabaseHas('inventory_items', ['product_id' => $product->id]);
        $this->assertDatabaseHas('inventory_movements', ['product_id' => $product->id]);
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function branch(array $overrides = []): Branch
    {
        return Branch::create([
            'code' => 'BAQ',
            'slug' => 'barranquilla',
            'name' => 'Barranquilla',
            'city' => 'Barranquilla',
            'is_active' => true,
            ...$overrides,
        ]);
    }

    private function product(?string $sku = null): Product
    {
        $unique = uniqid();

        return Product::create([
            'name' => "Producto {$unique}",
            'slug' => "producto-{$unique}",
            'sku' => $sku ?? "P-{$unique}",
            'price' => 100000,
            'cost_price' => 40000,
            'is_active' => true,
            'is_visible' => true,
        ]);
    }

    private function variant(
        Product $product,
        string $name,
        ?string $sku = null,
    ): ProductVariant {
        $unique = uniqid();

        return ProductVariant::create([
            'product_id' => $product->id,
            'name' => $name,
            'normalized_name' => strtolower($name),
            'sku' => $sku ?? "V-{$unique}",
            'price' => 120000,
            'cost_price' => 50000,
            'is_default' => true,
            'is_active' => true,
            'is_visible' => true,
        ]);
    }

    private function movementPayload(
        Branch $branch,
        Product $product,
        ?ProductVariant $variant = null,
        string $type = 'entry',
        int $quantity = 1,
    ): array {
        return [
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant?->id,
            'type' => $type,
            'quantity' => $quantity,
            'reason' => 'Prueba multisedes',
        ];
    }

    private function minimumPayload(
        Branch $branch,
        Product $product,
        int $minimum,
        ?ProductVariant $variant = null,
    ): array {
        return [
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant?->id,
            'minimum_quantity' => $minimum,
        ];
    }
}
