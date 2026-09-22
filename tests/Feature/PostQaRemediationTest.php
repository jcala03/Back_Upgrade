<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\InventoryItem;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\User;
use App\Models\UserCapability;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class PostQaRemediationTest extends TestCase
{
    use RefreshDatabase;

    public function createApplication()
    {
        $connection = trim((string) getenv('TEST_DB_CONNECTION'));
        $database = trim((string) getenv('TEST_DB_DATABASE'));
        if ($connection !== 'mysql' || $database === '' || strcasecmp($database, 'upgrade') === 0) {
            throw new RuntimeException('PostQaRemediationTest requiere MySQL temporal distinta de upgrade.');
        }

        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $app['config']->set('database.default', $connection);
        $app['config']->set("database.connections.{$connection}.database", $database);

        return $app;
    }

    public function test_inactive_accounts_are_rejected_centrally_but_active_accounts_work(): void
    {
        $active = User::factory()->create(['role' => User::ROLE_USER, 'is_active' => true]);
        Sanctum::actingAs($active);
        $this->getJson('/api/auth/me')->assertOk();

        $active->update(['is_active' => false]);
        $this->getJson('/api/auth/me')->assertForbidden()
            ->assertJsonPath('message', 'Tu cuenta está desactivada.');
        $this->getJson('/api/admin/notifications')->assertForbidden();

        $inactiveAdmin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => false]);
        Sanctum::actingAs($inactiveAdmin);
        $this->getJson('/api/admin/users')->assertForbidden();

        $inactiveUser = User::factory()->create(['role' => User::ROLE_USER, 'is_active' => false]);
        $branch = $this->branch('CAP');
        Employee::create(['user_id' => $inactiveUser->id, 'branch_id' => $branch->id, 'name' => 'Inactivo', 'job_title' => 'QA', 'is_active' => true]);
        UserCapability::create(['user_id' => $inactiveUser->id, 'capability' => UserCapability::ORDERS_VIEW_OWN]);
        Sanctum::actingAs($inactiveUser);
        $this->getJson('/api/my/sales')->assertForbidden();
    }

    public function test_inactive_login_is_rejected_and_logout_remains_available(): void
    {
        $inactive = User::factory()->create([
            'role' => User::ROLE_USER,
            'is_active' => false,
            'password' => 'Password123',
        ]);
        $this->postJson('/api/auth/login', [
            'email' => $inactive->email,
            'password' => 'Password123',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        Sanctum::actingAs($inactive);
        $this->postJson('/api/auth/logout')->assertOk();
    }

    public function test_deactivation_revokes_sanctum_tokens_and_database_sessions(): void
    {
        config()->set('session.driver', 'database');
        $actor = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
        $target = User::factory()->create(['role' => User::ROLE_USER, 'is_active' => true]);
        $actorToken = $actor->createToken('actor')->plainTextToken;
        $targetToken = $target->createToken('target')->plainTextToken;
        DB::table('sessions')->insert([
            'id' => 'target-session',
            'user_id' => $target->id,
            'payload' => 'test',
            'last_activity' => now()->timestamp,
        ]);

        $this->withToken($actorToken)
            ->patchJson("/api/admin/users/{$target->id}", ['is_active' => false])
            ->assertOk();
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_type' => User::class,
            'tokenable_id' => $target->id,
        ]);
        $this->assertDatabaseMissing('sessions', ['user_id' => $target->id]);

        $target->update(['is_active' => true]);
        $this->app['auth']->forgetGuards();
        $this->withToken($targetToken)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_public_product_index_and_show_use_recursive_financial_allowlists(): void
    {
        $product = $this->product([
            'commission_enabled' => true,
            'commission_amount' => 25000,
        ]);
        $variant = $product->variants()->create($this->variantData());
        $forbidden = [
            'cost_price', 'tax_amount', 'extra_charges', 'total_cost', 'profit_amount',
            'profit_margin_percent', 'markup_percent', 'pricing_mode', 'target_profit_percent',
            'commission_enabled', 'commission_amount', 'initial_stock', 'minimum_stock',
            'created_at', 'updated_at',
        ];

        foreach (['/api/products', "/api/products/{$product->slug}"] as $path) {
            $response = $this->getJson($path)->assertOk();
            $base = $path === '/api/products' ? 'data.0' : 'data';
            $response->assertJsonPath("{$base}.id", $product->id)
                ->assertJsonPath("{$base}.price", 200000)
                ->assertJsonPath("{$base}.variants.0.id", $variant->id)
                ->assertJsonPath("{$base}.variants.0.price", 180000);
            foreach ($forbidden as $field) {
                $response->assertJsonMissingPath("{$base}.{$field}");
                $response->assertJsonMissingPath("{$base}.variants.0.{$field}");
            }
        }

        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]));
        $this->getJson('/api/admin/products')->assertOk()
            ->assertJsonPath('data.0.cost_price', 70000)
            ->assertJsonPath('data.0.commission_enabled', true)
            ->assertJsonPath('data.0.variants.0.cost_price', 60000);
    }

    public function test_inventory_overview_uses_company_totals_without_legacy_stock(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]));
        $baq = $this->branch('BAQ');
        $bog = $this->branch('BOG');
        $product = $this->product(['name' => 'Simple', 'slug' => 'simple']);
        $item = InventoryItem::create(['product_id' => $product->id]);
        InventoryStock::create(['branch_id' => $baq->id, 'inventory_item_id' => $item->id, 'quantity' => 4, 'minimum_quantity' => 5]);
        InventoryStock::create(['branch_id' => $bog->id, 'inventory_item_id' => $item->id, 'quantity' => 10, 'minimum_quantity' => 3]);

        $response = $this->getJson('/api/admin/inventory')->assertOk();
        $index = collect($response->json('data.products'))->search(fn (array $row): bool => $row['id'] === $product->id);
        $response->assertJsonPath("data.products.{$index}.stock", 14)
            ->assertJsonPath("data.products.{$index}.stock_total", 14)
            ->assertJsonPath("data.products.{$index}.stock_status", 'low_stock')
            ->assertJsonPath("data.products.{$index}.is_low_stock", true)
            ->assertJsonMissingPath("data.products.{$index}.minimum_stock")
            ->assertJsonMissingPath("data.products.{$index}.initial_stock");
    }

    public function test_inventory_overview_keeps_uninitialized_products_and_variants_without_get_side_effects(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]));
        $simple = $this->product(['name' => 'Sin posición', 'slug' => 'sin-posicion']);
        $parent = $this->product(['name' => 'Con variantes', 'slug' => 'con-variantes']);
        $withStock = $parent->variants()->create($this->variantData(['name' => 'Con stock', 'sku' => 'WITH-STOCK']));
        $withoutStock = $parent->variants()->create($this->variantData(['name' => 'Sin stock', 'sku' => 'WITHOUT-STOCK']));
        $item = InventoryItem::create(['product_variant_id' => $withStock->id]);
        InventoryStock::create(['branch_id' => $this->branch('BAQ')->id, 'inventory_item_id' => $item->id, 'quantity' => 9, 'minimum_quantity' => 2]);
        $itemsBefore = InventoryItem::count();
        $stocksBefore = InventoryStock::count();

        $response = $this->getJson('/api/admin/inventory')->assertOk();
        $rows = collect($response->json('data.products'));
        $simpleIndex = $rows->search(fn (array $row): bool => $row['id'] === $simple->id);
        $parentIndex = $rows->search(fn (array $row): bool => $row['id'] === $parent->id);
        $variants = collect($response->json("data.products.{$parentIndex}.variants"));
        $withIndex = $variants->search(fn (array $row): bool => $row['id'] === $withStock->id);
        $withoutIndex = $variants->search(fn (array $row): bool => $row['id'] === $withoutStock->id);

        $response->assertJsonPath("data.products.{$simpleIndex}.stock", 0)
            ->assertJsonPath("data.products.{$simpleIndex}.stock_status", 'out_of_stock')
            ->assertJsonPath("data.products.{$parentIndex}.stock", 9)
            ->assertJsonPath("data.products.{$parentIndex}.stock_status", 'low_stock')
            ->assertJsonPath("data.products.{$parentIndex}.variants.{$withIndex}.stock", 9)
            ->assertJsonPath("data.products.{$parentIndex}.variants.{$withoutIndex}.stock", 0);
        $this->assertSame($itemsBefore, InventoryItem::count());
        $this->assertSame($stocksBefore, InventoryStock::count());
    }

    private function product(array $overrides = []): Product
    {
        return Product::create(array_replace([
            'name' => 'Producto QA',
            'normalized_name' => 'producto qa',
            'slug' => 'producto-qa-'.fake()->unique()->numerify('####'),
            'sku' => 'P-'.fake()->unique()->numerify('####'),
            'price' => 200000,
            'cost_price' => 70000,
            'tax_amount' => 10000,
            'extra_charges' => 5000,
            'total_cost' => 85000,
            'profit_amount' => 115000,
            'profit_margin_percent' => 57.5,
            'markup_percent' => 135.29,
            'pricing_mode' => 'manual',
            'is_active' => true,
            'is_visible' => true,
        ], $overrides));
    }

    private function variantData(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Variante QA',
            'normalized_name' => 'variante qa',
            'sku' => 'V-'.fake()->unique()->numerify('####'),
            'price' => 180000,
            'cost_price' => 60000,
            'tax_amount' => 9000,
            'extra_charges' => 4000,
            'total_cost' => 73000,
            'profit_amount' => 107000,
            'profit_margin_percent' => 59.44,
            'markup_percent' => 146.58,
            'pricing_mode' => 'manual',
            'is_active' => true,
            'is_visible' => true,
        ], $overrides);
    }

    private function branch(string $code): Branch
    {
        return Branch::create([
            'code' => $code,
            'slug' => strtolower($code),
            'name' => $code,
            'city' => $code === 'BOG' ? 'Bogotá' : 'Barranquilla',
            'is_active' => true,
        ]);
    }
}
