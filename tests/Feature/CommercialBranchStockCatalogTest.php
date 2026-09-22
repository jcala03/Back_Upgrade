<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\UserCapability;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class CommercialBranchStockCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function createApplication()
    {
        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        $connection = trim((string) getenv('TEST_DB_CONNECTION'));
        if ($connection !== '') {
            $database = trim((string) getenv('TEST_DB_DATABASE')) ?: 'upgrade_commercial_catalog_test';
            if (strcasecmp($database, 'upgrade') === 0) {
                throw new RuntimeException('CommercialBranchStockCatalogTest no puede ejecutarse contra la base principal upgrade.');
            }
            $app['config']->set('database.default', $connection);
            $app['config']->set("database.connections.{$connection}.database", $database);
        }

        $defaultConnection = $app['config']->get('database.default');
        $configuredDatabase = (string) $app['config']->get("database.connections.{$defaultConnection}.database");
        if (strcasecmp($configuredDatabase, 'upgrade') === 0) {
            throw new RuntimeException('CommercialBranchStockCatalogTest no puede ejecutarse contra la base principal upgrade.');
        }

        return $app;
    }

    public function test_admin_catalog_requires_orders_create_active_branch_and_uses_branch_stock(): void
    {
        $baq = $this->branch('BAQ');
        $bog = $this->branch('BOG');
        $legacyMismatch = $this->product([
            'name' => 'A Cable Comercial',
            'sku' => 'CAB-COM',
        ]);
        $withoutPosition = $this->product([
            'name' => 'B Sin Posición',
            'sku' => 'ZERO-POS',
        ]);
        $this->stock($baq, $legacyMismatch, 5);
        $this->stock($bog, $legacyMismatch, 0);

        $this->getJson("/api/admin/commercial-products?branch_id={$baq->id}")
            ->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create(['role' => 'viewer', 'is_active' => true]));
        $this->getJson("/api/admin/commercial-products?branch_id={$baq->id}")
            ->assertForbidden();

        $commercialOnly = $this->userWithPermissions(['orders.create']);
        Sanctum::actingAs($commercialOnly);
        $this->getJson("/api/admin/inventory/stocks?branch_id={$baq->id}")
            ->assertForbidden();

        $response = $this->getJson("/api/admin/commercial-products?branch_id={$baq->id}")
            ->assertOk()
            ->assertJsonPath('data.branch.id', $baq->id)
            ->assertJsonPath('data.branch.code', $baq->code);

        $products = collect($response->json('data.products'))->keyBy('id');
        $this->assertSame(5, $products[$legacyMismatch->id]['branch_stock']);
        $this->assertSame(0, $products[$withoutPosition->id]['branch_stock']);

        $bogProducts = collect(
            $this->getJson("/api/admin/commercial-products?branch_id={$bog->id}")
                ->assertOk()
                ->json('data.products')
        )->keyBy('id');
        $this->assertSame(0, $bogProducts[$legacyMismatch->id]['branch_stock']);

        $inactiveBranch = $this->branch('INA', false);
        $this->getJson("/api/admin/commercial-products?branch_id={$inactiveBranch->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('branch_id');
    }

    public function test_variant_catalog_sums_eligible_variant_stock_and_excludes_parent_inventory_item(): void
    {
        $this->admin();
        $branch = $this->branch('VAR');
        $product = $this->product(['name' => 'Producto con Variantes']);
        $variantA = $this->variant($product, 'Android', ['sku' => 'VAR-A', 'sort_order' => 1]);
        $variantB = $this->variant($product, 'Linux', ['sku' => 'VAR-B', 'sort_order' => 2]);
        $inactiveVariant = $this->variant($product, 'Inactiva', [
            'sku' => 'VAR-INACTIVE',
            'is_active' => false,
            'sort_order' => 3,
        ]);
        $this->stock($branch, $product, 100);
        $this->stock($branch, $product, 3, $variantA);
        $this->stock($branch, $product, 2, $variantB);
        $this->stock($branch, $product, 50, $inactiveVariant);

        $response = $this->getJson("/api/admin/commercial-products?branch_id={$branch->id}&search=Variantes")
            ->assertOk()
            ->assertJsonPath('data.products.0.id', $product->id)
            ->assertJsonPath('data.products.0.has_variants', true)
            ->assertJsonPath('data.products.0.branch_stock', 5)
            ->assertJsonCount(2, 'data.products.0.variants');

        $variants = collect($response->json('data.products.0.variants'))->keyBy('id');
        $this->assertSame(3, $variants[$variantA->id]['branch_stock']);
        $this->assertSame(2, $variants[$variantB->id]['branch_stock']);
        $this->assertFalse($variants->has($inactiveVariant->id));
    }

    public function test_my_catalog_derives_employee_branch_and_rejects_branch_spoofing(): void
    {
        $baq = $this->branch('BAQ');
        $bog = $this->branch('BOG');
        [$user] = $this->commercialUser($baq);
        $product = $this->product(['name' => 'Producto Personal']);
        $this->stock($baq, $product, 4);
        $this->stock($bog, $product, 9);

        Sanctum::actingAs($user);
        $this->getJson('/api/my/commercial-products')
            ->assertOk()
            ->assertJsonPath('data.branch.id', $baq->id)
            ->assertJsonPath('data.products.0.id', $product->id)
            ->assertJsonPath('data.products.0.branch_stock', 4);

        $this->getJson("/api/my/commercial-products?branch_id={$bog->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('branch_id');
    }

    public function test_my_catalog_uses_same_operational_employee_context_as_my_sale_creation(): void
    {
        $branch = $this->branch('CTX');

        $withoutEmployee = $this->personalUser();
        Sanctum::actingAs($withoutEmployee);
        $this->getJson('/api/my/commercial-products')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('employee');

        [$inactiveEmployeeUser, $inactiveEmployee] = $this->commercialUser($branch);
        $inactiveEmployee->update(['is_active' => false]);
        Sanctum::actingAs($inactiveEmployeeUser);
        $this->getJson('/api/my/commercial-products')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('employee');

        $withoutBranch = $this->personalUser();
        Employee::create([
            'user_id' => $withoutBranch->id,
            'branch_id' => null,
            'name' => 'Sin sede',
            'job_title' => 'Asesor',
            'is_active' => true,
        ]);
        Sanctum::actingAs($withoutBranch);
        $this->getJson('/api/my/commercial-products')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('branch_id');

        $inactiveBranch = $this->branch('OFF', false);
        [$inactiveBranchUser] = $this->commercialUser($inactiveBranch);
        Sanctum::actingAs($inactiveBranchUser);
        $this->getJson('/api/my/commercial-products')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('branch_id');
    }

    public function test_commercial_catalog_get_has_no_inventory_side_effects(): void
    {
        $this->admin();
        $branch = $this->branch('RO');
        $product = $this->product(['name' => 'Sin InventoryItem']);
        $itemsBefore = InventoryItem::count();
        $stocksBefore = InventoryStock::count();
        $movementsBefore = InventoryMovement::count();

        $this->getJson("/api/admin/commercial-products?branch_id={$branch->id}")
            ->assertOk()
            ->assertJsonPath('data.products.0.id', $product->id)
            ->assertJsonPath('data.products.0.branch_stock', 0);

        $this->assertSame($itemsBefore, InventoryItem::count());
        $this->assertSame($stocksBefore, InventoryStock::count());
        $this->assertSame($movementsBefore, InventoryMovement::count());
    }

    public function test_my_catalog_response_is_reduced_and_private(): void
    {
        $branch = $this->branch('PRI');
        [$user] = $this->commercialUser($branch);
        $product = $this->product([
            'name' => 'Privacidad Producto',
            'cost_price' => 55000,
            'commission_enabled' => true,
            'commission_amount' => 10000,
        ]);
        $variant = $this->variant($product, 'Privacidad Variante', [
            'cost_price' => 45000,
        ]);
        $this->stock($branch, $product, 7, $variant);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/my/commercial-products')
            ->assertOk()
            ->assertJsonPath('data.products.0.id', $product->id)
            ->assertJsonPath('data.products.0.price', $product->price)
            ->assertJsonPath('data.products.0.branch_stock', 7)
            ->assertJsonPath('data.products.0.variants.0.id', $variant->id)
            ->assertJsonPath('data.products.0.variants.0.price', $variant->price)
            ->assertJsonPath('data.products.0.variants.0.branch_stock', 7)
            ->assertJsonMissingPath('data.products.0.stock')
            ->assertJsonMissingPath('data.products.0.initial_stock')
            ->assertJsonMissingPath('data.products.0.minimum_stock')
            ->assertJsonMissingPath('data.products.0.cost_price')
            ->assertJsonMissingPath('data.products.0.commission_enabled')
            ->assertJsonMissingPath('data.products.0.commission_amount')
            ->assertJsonMissingPath('data.products.0.variants.0.stock')
            ->assertJsonMissingPath('data.products.0.variants.0.initial_stock')
            ->assertJsonMissingPath('data.products.0.variants.0.minimum_stock')
            ->assertJsonMissingPath('data.products.0.variants.0.cost_price');

        $content = $response->getContent();
        $this->assertStringNotContainsString('profit', $content);
        $this->assertStringNotContainsString('margin', $content);
        $this->assertStringNotContainsString('commission', $content);
    }

    public function test_search_limit_and_query_count_are_bounded(): void
    {
        $this->admin();
        $branch = $this->branch('SEA');
        $matching = $this->product(['name' => 'Alpha Comercial', 'sku' => 'ALPHA-ONE']);
        $this->product(['name' => 'Beta Comercial', 'sku' => 'BETA-TWO']);
        foreach (range(1, 5) as $index) {
            $product = $this->product(['name' => "Carga {$index}"]);
            $variant = $this->variant($product, "Variante {$index}");
            $this->stock($branch, $product, $index, $variant);
        }

        $this->getJson("/api/admin/commercial-products?branch_id={$branch->id}&search=A&limit=1")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('search');

        $this->getJson("/api/admin/commercial-products?branch_id={$branch->id}&search=Alpha&limit=1")
            ->assertOk()
            ->assertJsonCount(1, 'data.products')
            ->assertJsonPath('data.products.0.id', $matching->id);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson("/api/admin/commercial-products?branch_id={$branch->id}")
            ->assertOk();
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(12, $queryCount);
    }

    public function test_public_network_stock_and_admin_product_contracts_remain_unchanged(): void
    {
        $product = $this->product([
            'name' => 'Contrato Público',
            'slug' => 'contrato-publico',
            'cost_price' => 31000,
        ]);
        $branch = $this->branch('PUB');
        $this->stock($branch, $product, 7);

        $this->getJson('/api/products')
            ->assertOk()
            ->assertJsonPath('data.0.id', $product->id)
            ->assertJsonPath('data.0.stock', 7);

        $this->admin();
        $this->getJson('/api/admin/products')
            ->assertOk()
            ->assertJsonPath('data.0.id', $product->id)
            ->assertJsonPath('data.0.cost_price', 31000);
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
        Sanctum::actingAs($admin);

        return $admin;
    }

    /**
     * @return array{0: User, 1: Employee}
     */
    private function commercialUser(Branch $branch): array
    {
        $user = $this->personalUser();
        $employee = Employee::create([
            'user_id' => $user->id,
            'branch_id' => $branch->id,
            'name' => 'Asesor comercial',
            'job_title' => 'Asesor',
            'is_active' => true,
        ]);

        return [$user, $employee];
    }

    private function personalUser(): User
    {
        $user = User::factory()->create(['role' => User::ROLE_USER, 'is_active' => true]);
        UserCapability::create([
            'user_id' => $user->id,
            'capability' => UserCapability::ORDERS_CREATE_OWN,
        ]);
        $user->unsetRelation('capabilities');

        return $user;
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function userWithPermissions(array $permissions): User
    {
        $user = new class extends User
        {
            /** @var array<int, string> */
            public array $testPermissions = [];

            public function permissions(): array
            {
                return $this->testPermissions;
            }
        };
        $user->setTable('users');
        $user->testPermissions = $permissions;
        $user->forceFill([
            'name' => 'Commercial catalog scoped user',
            'email' => 'commercial-catalog-'.uniqid().'@example.test',
            'password' => 'password',
            'role' => User::ROLE_USER,
            'is_active' => true,
        ])->save();

        return $user;
    }

    private function branch(string $codePrefix, bool $active = true): Branch
    {
        $unique = substr(str_replace('.', '', uniqid('', true)), -6);
        $code = substr($codePrefix.$unique, 0, 12);

        return Branch::create([
            'code' => $code,
            'slug' => strtolower($codePrefix).'-'.$unique,
            'name' => "Sede {$codePrefix}",
            'city' => 'Barranquilla',
            'is_active' => $active,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
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

    /**
     * @param  array<string, mixed>  $overrides
     */
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
        ?ProductVariant $variant = null,
    ): InventoryStock {
        $item = InventoryItem::firstOrCreate($variant
            ? ['product_id' => null, 'product_variant_id' => $variant->id]
            : ['product_id' => $product->id, 'product_variant_id' => null]);

        return InventoryStock::create([
            'branch_id' => $branch->id,
            'inventory_item_id' => $item->id,
            'quantity' => $quantity,
            'minimum_quantity' => 0,
        ]);
    }
}
