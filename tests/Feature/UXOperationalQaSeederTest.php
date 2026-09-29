<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\EmployeeWorkSchedule;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Service;
use App\Models\User;
use App\Models\UserCapability;
use Database\Seeders\UXOperationalQaSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class UXOperationalQaSeederTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setEnvironmentValue('UX_QA_ADMIN_PASSWORD', Str::password(24));
        $this->setEnvironmentValue('UX_QA_COLLABORATOR_PASSWORD', Str::password(24));
    }

    protected function tearDown(): void
    {
        $this->unsetEnvironmentValue('UX_QA_ADMIN_PASSWORD');
        $this->unsetEnvironmentValue('UX_QA_COLLABORATOR_PASSWORD');

        parent::tearDown();
    }

    public function test_seeder_is_idempotent_and_builds_critical_operational_relations(): void
    {
        $this->seed(UXOperationalQaSeeder::class);
        $firstCounts = $this->qaCounts();

        $this->seed(UXOperationalQaSeeder::class);

        $this->assertSame($firstCounts, $this->qaCounts());
        $this->assertSame(2, Branch::query()->whereIn('code', ['BAQ', 'BOG'])->where('is_active', true)->count());

        $collaborator = User::query()->where('email', 'collaborator.ux@qa.local')->firstOrFail();
        $employee = Employee::query()->where('user_id', $collaborator->id)->firstOrFail();
        $this->assertTrue($employee->is_active);
        $this->assertNotNull($employee->branch_id);
        $this->assertSame(count(UserCapability::allowed()), $collaborator->capabilities()->count());
        $this->assertSame(6, EmployeeWorkSchedule::query()->where('employee_id', $employee->id)->count());

        $this->assertDatabaseHas('customer_vehicles', [
            'customer_id' => Customer::query()->where('email', 'cliente.principal@qa.local')->value('id'),
            'plate' => 'QAUX01',
            'is_active' => true,
        ]);

        $simple = Product::query()->where('sku', 'QA-UX-SIMPLE-001')->firstOrFail();
        $variant = ProductVariant::query()->where('sku', 'QA-UX-VAR-MACAN-001')->firstOrFail();
        $this->assertSame(15, $this->networkStockForProduct($simple));
        $this->assertSame(15, $this->networkStockForVariant($variant));
        $this->assertSame(0, $this->networkStockForProduct(
            Product::query()->where('sku', 'QA-UX-OOS-001')->firstOrFail(),
        ));

        $this->assertDatabaseHas('product_variant_compatibilities', [
            'product_variant_id' => $variant->id,
            'year_from' => 2022,
            'year_to' => 2026,
        ]);
        $this->assertTrue(Service::query()->where('slug', 'qa-ux-instalacion-tecnica')->where('is_active', true)->exists());
    }

    public function test_public_api_exposes_purchasable_variant_and_out_of_stock_scenarios(): void
    {
        $this->seed(UXOperationalQaSeeder::class);

        $products = collect($this->getJson('/api/products')->assertOk()->json('data'));

        $simple = $products->firstWhere('sku', 'QA-UX-SIMPLE-001');
        $variantProduct = $products->firstWhere('sku', 'QA-UX-VARIANT-PARENT');
        $outOfStock = $products->firstWhere('sku', 'QA-UX-OOS-001');

        $this->assertNotNull($simple);
        $this->assertSame(15, $simple['stock']);
        $this->assertSame('available', $simple['stock_status']);
        $this->assertGreaterThan(0, $simple['price']);

        $this->assertNotNull($variantProduct);
        $this->assertTrue($variantProduct['has_variants']);
        $this->assertSame('QA-UX-VAR-MACAN-001', $variantProduct['variants'][0]['sku']);
        $this->assertSame(15, $variantProduct['variants'][0]['stock']);

        $this->assertNotNull($outOfStock);
        $this->assertSame(0, $outOfStock['stock']);
        $this->assertSame('out_of_stock', $outOfStock['stock_status']);
    }

    public function test_seeder_refuses_a_non_upgrade_test_configuration_before_writing(): void
    {
        $configuredDatabase = config('database.connections.mysql.database');
        config()->set('database.connections.mysql.database', 'upgrade');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Unsafe QA seeder target');

            (new UXOperationalQaSeeder)->run();
        } finally {
            config()->set('database.connections.mysql.database', $configuredDatabase);
        }
    }

    public function test_seeder_refuses_browser_qa_database_while_phpunit_is_running(): void
    {
        $configuredDatabase = config('database.connections.mysql.database');
        $environmentDatabase = $this->environmentValue('DB_DATABASE');
        config()->set('database.connections.mysql.database', 'upgrade_ux_qa');
        $this->setEnvironmentValue('DB_DATABASE', 'upgrade_ux_qa');
        $this->setEnvironmentValue('UX_QA_BROWSER', 'true');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Unsafe QA seeder target');

            (new UXOperationalQaSeeder)->run();
        } finally {
            config()->set('database.connections.mysql.database', $configuredDatabase);
            $this->setEnvironmentValue('DB_DATABASE', $environmentDatabase ?? 'upgrade_test');
            $this->unsetEnvironmentValue('UX_QA_BROWSER');
        }
    }

    /** @return array<string, int> */
    private function qaCounts(): array
    {
        return [
            'users' => User::query()->where('email', 'like', '%@qa.local')->count(),
            'employees' => Employee::query()->where('name', 'like', '[QA UX]%')->count(),
            'customers' => Customer::query()->where('name', 'like', '[QA UX]%')->count(),
            'products' => Product::query()->where('sku', 'like', 'QA-UX-%')->count(),
            'variants' => ProductVariant::query()->where('sku', 'like', 'QA-UX-%')->count(),
            'services' => Service::query()->where('name', 'like', '[QA UX]%')->count(),
        ];
    }

    private function networkStockForProduct(Product $product): int
    {
        return (int) InventoryStock::query()
            ->whereHas('inventoryItem', fn ($query) => $query
                ->where('product_id', $product->id)
                ->whereNull('product_variant_id'))
            ->sum('quantity');
    }

    private function networkStockForVariant(ProductVariant $variant): int
    {
        return (int) InventoryStock::query()
            ->whereHas('inventoryItem', fn ($query) => $query
                ->whereNull('product_id')
                ->where('product_variant_id', $variant->id))
            ->sum('quantity');
    }

    private function setEnvironmentValue(string $key, string $value): void
    {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    private function unsetEnvironmentValue(string $key): void
    {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }

    private function environmentValue(string $key): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        return is_string($value) ? $value : null;
    }
}
