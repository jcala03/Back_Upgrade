<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerVehicle;
use App\Models\Employee;
use App\Models\EmployeeBranchAssignment;
use App\Models\EmployeeWorkSchedule;
use App\Models\InventoryItem;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\ProductVariantCompatibility;
use App\Models\ProductVehicleCompatibility;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Models\UserCapability;
use App\Models\VehicleBrand;
use App\Models\VehicleModel;
use App\Models\VehicleMultimediaSystem;
use App\Models\VehicleVersion;
use App\Services\ProductImageService;
use Illuminate\Database\Seeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class UXOperationalQaSeeder extends Seeder
{
    private const PHPUNIT_DATABASE = 'upgrade_test';

    private const BROWSER_QA_DATABASE = 'upgrade_ux_qa';

    private const ADMIN_EMAIL = 'admin.ux@qa.local';

    private const COLLABORATOR_EMAIL = 'collaborator.ux@qa.local';

    public function run(): void
    {
        $this->guardTargetDatabase();

        $adminPassword = $this->qaPassword('UX_QA_ADMIN_PASSWORD');
        $collaboratorPassword = $this->qaPassword('UX_QA_COLLABORATOR_PASSWORD');

        DB::transaction(function () use ($adminPassword, $collaboratorPassword): void {
            [$barranquilla, $bogota] = $this->seedBranches();
            [$admin, $collaborator] = $this->seedUsers($adminPassword, $collaboratorPassword);
            $employee = $this->seedEmployee($collaborator, $admin, $barranquilla);
            $this->seedSchedule($employee, $admin);

            [$vehicleBrand, $vehicleModel, $vehicleVersion, $multimediaSystem] = $this->seedVehicleCatalog();
            $this->seedCustomer($admin, $vehicleBrand, $vehicleModel, $vehicleVersion);

            $category = $this->seedProductCategory();
            $products = $this->seedProducts(
                $category,
                $vehicleBrand,
                $vehicleModel,
                $vehicleVersion,
                $multimediaSystem,
            );

            $this->seedInventory($products, $barranquilla, $bogota);
            $this->seedService();
        }, 3);

        $this->writeSyntheticProductImages();

        $this->command?->info(
            'Deterministic UX operational QA dataset is ready in '.DB::connection()->getDatabaseName().'.',
        );
    }

    private function guardTargetDatabase(): void
    {
        $environment = app()->environment();
        $connection = config('database.default');
        $configuredDatabase = config('database.connections.mysql.database');
        $environmentConnection = $this->environmentValue('DB_CONNECTION');
        $environmentDatabase = $this->environmentValue('DB_DATABASE');

        $isPhpunitTarget = $environment === 'testing'
            && $configuredDatabase === self::PHPUNIT_DATABASE
            && $environmentDatabase === self::PHPUNIT_DATABASE
            && $this->environmentValue('TEST_DB_CONNECTION') === 'mysql'
            && $this->environmentValue('TEST_DB_DATABASE') === self::PHPUNIT_DATABASE;

        $isBrowserQaTarget = $environment === 'local'
            && $configuredDatabase === self::BROWSER_QA_DATABASE
            && $environmentDatabase === self::BROWSER_QA_DATABASE
            && filter_var($this->environmentValue('UX_QA_BROWSER'), FILTER_VALIDATE_BOOL);

        if (
            $connection !== 'mysql'
            || $environmentConnection !== 'mysql'
            || (! $isPhpunitTarget && ! $isBrowserQaTarget)
        ) {
            throw new RuntimeException(
                'Unsafe QA seeder target. Expected either PHPUnit '
                .'(testing/mysql/upgrade_test) or explicit browser QA '
                .'(local/mysql/upgrade_ux_qa with UX_QA_BROWSER=true).'
            );
        }

        $expected = $isPhpunitTarget
            ? self::PHPUNIT_DATABASE
            : self::BROWSER_QA_DATABASE;

        $database = DB::selectOne('SELECT DATABASE() AS db_name')?->db_name;

        if ($database !== $expected) {
            throw new RuntimeException(
                "Unsafe effective database. SELECT DATABASE() must return {$expected}."
            );
        }
    }

    /** @return array{0: Branch, 1: Branch} */
    private function seedBranches(): array
    {
        $barranquilla = Branch::updateOrCreate(
            ['code' => 'BAQ'],
            [
                'slug' => 'barranquilla',
                'name' => 'Barranquilla',
                'city' => 'Barranquilla',
                'is_active' => true,
                'ecommerce_priority' => 10,
                'country_code' => 'CO',
                'state' => 'Atlántico',
                'postal_code' => '080001',
                'address_line1' => '[QA UX] Dirección sintética Barranquilla',
                'address_line2' => null,
            ],
        );

        $bogota = Branch::updateOrCreate(
            ['code' => 'BOG'],
            [
                'slug' => 'bogota',
                'name' => 'Bogotá',
                'city' => 'Bogotá',
                'is_active' => true,
                'ecommerce_priority' => 20,
                'country_code' => 'CO',
                'state' => 'Bogotá D.C.',
                'postal_code' => '110111',
                'address_line1' => '[QA UX] Dirección sintética Bogotá',
                'address_line2' => null,
            ],
        );

        return [$barranquilla, $bogota];
    }

    /** @return array{0: User, 1: User} */
    private function seedUsers(string $adminPassword, string $collaboratorPassword): array
    {
        $admin = User::updateOrCreate(
            ['email' => self::ADMIN_EMAIL],
            [
                'name' => '[QA UX] Administrador',
                'password' => Hash::make($adminPassword),
                'role' => User::ROLE_ADMIN,
                'is_active' => true,
            ],
        );

        $collaborator = User::updateOrCreate(
            ['email' => self::COLLABORATOR_EMAIL],
            [
                'name' => '[QA UX] Vendedor Barranquilla',
                'password' => Hash::make($collaboratorPassword),
                'role' => User::ROLE_USER,
                'is_active' => true,
            ],
        );

        foreach (UserCapability::allowed() as $capability) {
            UserCapability::updateOrCreate(
                ['user_id' => $collaborator->id, 'capability' => $capability],
                ['granted_by' => $admin->id],
            );
        }

        return [$admin, $collaborator];
    }

    private function seedEmployee(User $collaborator, User $admin, Branch $branch): Employee
    {
        $employee = Employee::updateOrCreate(
            ['user_id' => $collaborator->id],
            [
                'branch_id' => $branch->id,
                'name' => '[QA UX] Vendedor Barranquilla',
                'phone' => '3000000101',
                'job_title' => 'Asesor comercial QA',
                'specialty' => 'Ventas y cotizaciones QA',
                'notes' => '[QA UX] Empleado activo vinculado al colaborador QA.',
                'is_active' => true,
                'hire_date' => '2026-01-15',
            ],
        );

        EmployeeBranchAssignment::updateOrCreate(
            [
                'employee_id' => $employee->id,
                'reason' => '[QA UX] Asignación inicial',
            ],
            [
                'from_branch_id' => null,
                'to_branch_id' => $branch->id,
                'changed_by' => $admin->id,
                'changed_at' => '2026-01-15 08:00:00',
            ],
        );

        return $employee;
    }

    private function seedSchedule(Employee $employee, User $admin): void
    {
        foreach (range(1, 6) as $dayOfWeek) {
            EmployeeWorkSchedule::updateOrCreate(
                [
                    'employee_id' => $employee->id,
                    'day_of_week' => $dayOfWeek,
                    'effective_from' => '2026-01-01',
                ],
                [
                    'starts_at' => '08:00:00',
                    'ends_at' => '18:00:00',
                    'effective_until' => null,
                    'created_by' => $admin->id,
                ],
            );
        }
    }

    /** @return array{0: VehicleBrand, 1: VehicleModel, 2: VehicleVersion, 3: VehicleMultimediaSystem} */
    private function seedVehicleCatalog(): array
    {
        $brand = VehicleBrand::updateOrCreate(
            ['slug' => 'qa-ux-porsche'],
            [
                'name' => '[QA UX] Porsche',
                'description' => '[QA UX] Marca vehicular sintética para pruebas.',
                'is_active' => true,
            ],
        );

        $model = VehicleModel::updateOrCreate(
            ['vehicle_brand_id' => $brand->id, 'slug' => 'qa-ux-macan'],
            [
                'name' => '[QA UX] Macan',
                'description' => '[QA UX] Modelo sintético para compatibilidad.',
                'is_active' => true,
            ],
        );

        $version = VehicleVersion::updateOrCreate(
            [
                'vehicle_model_id' => $model->id,
                'name' => '[QA UX] Base',
                'year_from' => 2022,
                'year_to' => 2026,
            ],
            [
                'description' => '[QA UX] Versión sintética compatible.',
                'is_active' => true,
            ],
        );

        $system = VehicleMultimediaSystem::updateOrCreate(
            ['vehicle_brand_id' => $brand->id, 'slug' => 'qa-ux-pcm'],
            [
                'name' => '[QA UX] PCM',
                'code' => 'QA-UX-PCM',
                'description' => '[QA UX] Sistema OEM sintético.',
                'is_active' => true,
            ],
        );

        DB::table('vehicle_version_multimedia_system')->updateOrInsert(
            [
                'vehicle_version_id' => $version->id,
                'vehicle_multimedia_system_id' => $system->id,
            ],
            [
                'year_from' => 2022,
                'year_to' => 2026,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        return [$brand, $model, $version, $system];
    }

    private function seedCustomer(
        User $admin,
        VehicleBrand $brand,
        VehicleModel $model,
        VehicleVersion $version,
    ): void {
        $customer = Customer::updateOrCreate(
            ['email' => 'cliente.principal@qa.local'],
            [
                'name' => '[QA UX] Cliente Principal',
                'phone' => '3000000202',
                'phone_normalized' => '3000000202',
                'document' => 'QAUX0001',
                'city' => 'Barranquilla',
                'address' => '[QA UX] Dirección sintética cliente',
                'notes' => '[QA UX] Cliente reutilizable para flujos operativos.',
                'is_active' => true,
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
            ],
        );

        CustomerVehicle::updateOrCreate(
            ['customer_id' => $customer->id, 'plate' => 'QAUX01'],
            [
                'vehicle_brand_id' => $brand->id,
                'vehicle_model_id' => $model->id,
                'vehicle_version_id' => $version->id,
                'year' => 2024,
                'vin' => 'QAUXVIN0000000001',
                'color' => 'Azul QA',
                'nickname' => '[QA UX] Macan Principal',
                'notes' => '[QA UX] Vehículo sintético compatible con el catálogo QA.',
                'is_active' => true,
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
            ],
        );
    }

    private function seedProductCategory(): ProductCategory
    {
        return ProductCategory::updateOrCreate(
            ['slug' => 'qa-ux-multimedia'],
            [
                'name' => '[QA UX] Multimedia',
                'description' => '[QA UX] Categoría sintética para productos comprables.',
                'is_active' => true,
            ],
        );
    }

    /** @return array{simple: Product, variant: ProductVariant, out_of_stock: Product} */
    private function seedProducts(
        ProductCategory $category,
        VehicleBrand $brand,
        VehicleModel $model,
        VehicleVersion $version,
        VehicleMultimediaSystem $system,
    ): array {
        $simple = Product::updateOrCreate(
            ['sku' => 'QA-UX-SIMPLE-001'],
            [
                'name' => '[QA UX] Producto Simple',
                'normalized_name' => '[qa ux] producto simple',
                'slug' => 'qa-ux-producto-simple',
                'description' => '[QA UX] Producto universal con stock para ecommerce, pickup y venta CRM.',
                'category' => $category->name,
                'category_id' => $category->id,
                'compatibility_type' => Product::COMPATIBILITY_TYPE_UNIVERSAL,
                'price' => 950000,
                'cost_price' => 600000,
                'tax_amount' => 0,
                'extra_charges' => 0,
                'total_cost' => 600000,
                'profit_amount' => 350000,
                'profit_margin_percent' => 36.84,
                'markup_percent' => 58.33,
                'pricing_mode' => Product::PRICING_MODE_MANUAL,
                'target_profit_percent' => null,
                'commission_enabled' => true,
                'commission_amount' => 35000,
                'technical_specs' => ['qa_dataset' => 'UX-QA-01', 'type' => 'simple'],
                'main_image' => 'qa-ux/products/product-simple.svg',
                'requires_shipping' => true,
                'weight_grams' => 1800,
                'length_mm' => 320,
                'width_mm' => 220,
                'height_mm' => 120,
                'country_of_origin' => 'CO',
                'hs_code' => 'QAUX001',
                'customs_description' => '[QA UX] Producto sintético de prueba',
                'is_visible' => true,
                'is_featured' => true,
                'is_active' => true,
            ],
        );

        $variantProduct = Product::updateOrCreate(
            ['sku' => 'QA-UX-VARIANT-PARENT'],
            [
                'name' => '[QA UX] Pantalla Android Macan',
                'normalized_name' => '[qa ux] pantalla android macan',
                'slug' => 'qa-ux-pantalla-android-macan',
                'description' => '[QA UX] Producto con variante y compatibilidad vehicular completa.',
                'category' => $category->name,
                'category_id' => $category->id,
                'compatibility_type' => Product::COMPATIBILITY_TYPE_VEHICLE_SPECIFIC,
                'price' => 1350000,
                'cost_price' => 850000,
                'tax_amount' => 0,
                'extra_charges' => 0,
                'total_cost' => 850000,
                'profit_amount' => 500000,
                'profit_margin_percent' => 37.04,
                'markup_percent' => 58.82,
                'pricing_mode' => Product::PRICING_MODE_MANUAL,
                'target_profit_percent' => null,
                'commission_enabled' => true,
                'commission_amount' => 50000,
                'technical_specs' => ['qa_dataset' => 'UX-QA-01', 'type' => 'variant'],
                'main_image' => 'qa-ux/products/product-variant.svg',
                'requires_shipping' => true,
                'weight_grams' => 2400,
                'length_mm' => 360,
                'width_mm' => 240,
                'height_mm' => 150,
                'country_of_origin' => 'CO',
                'hs_code' => 'QAUX002',
                'customs_description' => '[QA UX] Pantalla sintética de prueba',
                'is_visible' => true,
                'is_featured' => true,
                'is_active' => true,
            ],
        );

        ProductVehicleCompatibility::updateOrCreate(
            [
                'product_id' => $variantProduct->id,
                'vehicle_brand_id' => $brand->id,
                'vehicle_model_id' => $model->id,
                'vehicle_version_id' => $version->id,
            ],
            ['notes' => '[QA UX] Compatibilidad principal del producto.'],
        );

        $variant = ProductVariant::updateOrCreate(
            ['sku' => 'QA-UX-VAR-MACAN-001'],
            [
                'product_id' => $variantProduct->id,
                'vehicle_multimedia_system_id' => $system->id,
                'compatibility_type' => Product::COMPATIBILITY_TYPE_VEHICLE_SPECIFIC,
                'name' => '[QA UX] 8 GB / 128 GB',
                'normalized_name' => '[qa ux] 8 gb / 128 gb',
                'attributes' => ['ram' => '8 GB', 'storage' => '128 GB'],
                'cost_price' => 850000,
                'tax_amount' => 0,
                'extra_charges' => 0,
                'total_cost' => 850000,
                'price' => 1350000,
                'profit_amount' => 500000,
                'profit_margin_percent' => 37.04,
                'markup_percent' => 58.82,
                'pricing_mode' => ProductVariant::PRICING_MODE_MANUAL,
                'target_profit_percent' => null,
                'main_image' => 'qa-ux/products/product-variant.svg',
                'weight_grams' => 2400,
                'length_mm' => 360,
                'width_mm' => 240,
                'height_mm' => 150,
                'country_of_origin' => 'CO',
                'hs_code' => 'QAUX002V',
                'customs_description' => '[QA UX] Variante sintética de prueba',
                'is_default' => true,
                'is_active' => true,
                'is_visible' => true,
                'sort_order' => 10,
            ],
        );

        ProductVariantCompatibility::updateOrCreate(
            [
                'product_variant_id' => $variant->id,
                'vehicle_brand_id' => $brand->id,
                'vehicle_model_id' => $model->id,
                'vehicle_version_id' => $version->id,
                'vehicle_multimedia_system_id' => $system->id,
            ],
            [
                'year_from' => 2022,
                'year_to' => 2026,
                'notes' => '[QA UX] Compatibilidad completa de la variante.',
            ],
        );

        $outOfStock = Product::updateOrCreate(
            ['sku' => 'QA-UX-OOS-001'],
            [
                'name' => '[QA UX] Producto Sin Stock',
                'normalized_name' => '[qa ux] producto sin stock',
                'slug' => 'qa-ux-producto-sin-stock',
                'description' => '[QA UX] Producto publicado para validar el estado sin stock.',
                'category' => $category->name,
                'category_id' => $category->id,
                'compatibility_type' => Product::COMPATIBILITY_TYPE_UNIVERSAL,
                'price' => 450000,
                'cost_price' => 250000,
                'tax_amount' => 0,
                'extra_charges' => 0,
                'total_cost' => 250000,
                'profit_amount' => 200000,
                'profit_margin_percent' => 44.44,
                'markup_percent' => 80.00,
                'pricing_mode' => Product::PRICING_MODE_MANUAL,
                'target_profit_percent' => null,
                'commission_enabled' => false,
                'commission_amount' => null,
                'technical_specs' => ['qa_dataset' => 'UX-QA-01', 'type' => 'out_of_stock'],
                'main_image' => 'qa-ux/products/product-out-of-stock.svg',
                'requires_shipping' => true,
                'weight_grams' => 900,
                'length_mm' => 200,
                'width_mm' => 160,
                'height_mm' => 80,
                'country_of_origin' => 'CO',
                'hs_code' => 'QAUX003',
                'customs_description' => '[QA UX] Producto agotado sintético',
                'is_visible' => true,
                'is_featured' => false,
                'is_active' => true,
            ],
        );

        foreach ([$simple, $variantProduct, $outOfStock] as $product) {
            $lockedProduct = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            app(ProductImageService::class)->sync($lockedProduct, new Request, [], null);
        }

        return [
            'simple' => $simple,
            'variant' => $variant,
            'out_of_stock' => $outOfStock,
        ];
    }

    /**
     * @param  array{simple: Product, variant: ProductVariant, out_of_stock: Product}  $products
     */
    private function seedInventory(array $products, Branch $barranquilla, Branch $bogota): void
    {
        $items = [
            'simple' => InventoryItem::firstOrCreate([
                'product_id' => $products['simple']->id,
                'product_variant_id' => null,
            ]),
            'variant' => InventoryItem::firstOrCreate([
                'product_id' => null,
                'product_variant_id' => $products['variant']->id,
            ]),
            'out_of_stock' => InventoryItem::firstOrCreate([
                'product_id' => $products['out_of_stock']->id,
                'product_variant_id' => null,
            ]),
        ];

        foreach ([
            [$barranquilla, $items['simple'], 10, 2],
            [$bogota, $items['simple'], 5, 2],
            [$barranquilla, $items['variant'], 10, 2],
            [$bogota, $items['variant'], 5, 2],
            [$barranquilla, $items['out_of_stock'], 0, 1],
            [$bogota, $items['out_of_stock'], 0, 1],
        ] as [$branch, $item, $quantity, $minimum]) {
            InventoryStock::updateOrCreate(
                ['branch_id' => $branch->id, 'inventory_item_id' => $item->id],
                ['quantity' => $quantity, 'minimum_quantity' => $minimum],
            );
        }
    }

    private function seedService(): void
    {
        $category = ServiceCategory::updateOrCreate(
            ['slug' => 'qa-ux-instalacion'],
            [
                'name' => '[QA UX] Instalación',
                'description' => '[QA UX] Categoría sintética de servicios.',
                'is_active' => true,
                'sort_order' => 10,
            ],
        );

        Service::updateOrCreate(
            ['slug' => 'qa-ux-instalacion-tecnica'],
            [
                'service_category_id' => $category->id,
                'name' => '[QA UX] Instalación Técnica',
                'description' => '[QA UX] Servicio activo para venta, cotización y cita.',
                'price' => 180000,
                'cost' => 80000,
                'estimated_duration_minutes' => 120,
                'is_active' => true,
                'sort_order' => 10,
            ],
        );
    }

    private function writeSyntheticProductImages(): void
    {
        foreach ([
            'product-simple.svg' => ['Producto Simple', '#0891b2'],
            'product-variant.svg' => ['Pantalla Android Macan', '#2563eb'],
            'product-out-of-stock.svg' => ['Producto Sin Stock', '#64748b'],
        ] as $filename => [$label, $accent]) {
            Storage::disk('public')->put(
                'qa-ux/products/'.$filename,
                $this->syntheticSvg($label, $accent),
            );
        }
    }

    private function syntheticSvg(string $label, string $accent): string
    {
        $safeLabel = htmlspecialchars($label, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return <<<SVG
            <svg xmlns="http://www.w3.org/2000/svg" width="1200" height="900" viewBox="0 0 1200 900" role="img" aria-labelledby="title desc">
              <title id="title">[QA UX] {$safeLabel}</title>
              <desc id="desc">Asset sintético para pruebas de interfaz</desc>
              <rect width="1200" height="900" fill="#07111b"/>
              <rect x="90" y="90" width="1020" height="720" rx="48" fill="#0f1f2d" stroke="{$accent}" stroke-width="8"/>
              <text x="600" y="390" text-anchor="middle" fill="{$accent}" font-family="Arial, sans-serif" font-size="78" font-weight="700">QA UX</text>
              <text x="600" y="500" text-anchor="middle" fill="#f8fafc" font-family="Arial, sans-serif" font-size="48">{$safeLabel}</text>
              <text x="600" y="590" text-anchor="middle" fill="#94a3b8" font-family="Arial, sans-serif" font-size="28">DATOS SINTÉTICOS · NO COMERCIAL</text>
            </svg>
            SVG;
    }

    private function qaPassword(string $key): string
    {
        $password = $this->environmentValue($key);

        if (! is_string($password) || strlen($password) < 12) {
            throw new RuntimeException("{$key} must be provided with at least 12 characters.");
        }

        return $password;
    }

    private function environmentValue(string $key): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        return is_string($value) ? $value : null;
    }
}
