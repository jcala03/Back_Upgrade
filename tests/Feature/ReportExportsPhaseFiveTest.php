<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\User;
use App\Services\Reports\ReportExportService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use OpenSpout\Reader\XLSX\Reader;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

class ReportExportsPhaseFiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-24 15:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function createApplication()
    {
        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $database = trim((string) getenv('TEST_DB_DATABASE'));
        if (getenv('TEST_DB_CONNECTION') !== 'mysql' || $database === '' || $database === 'upgrade') {
            throw new RuntimeException('ReportExportsPhaseFiveTest requiere MySQL temporal explícita.');
        }
        $app['config']->set('database.default', 'mysql');
        $app['config']->set('database.connections.mysql.database', $database);

        return $app;
    }

    public function test_all_eight_exports_are_real_xlsx_and_share_branch_filters(): void
    {
        $fixture = $this->fixture();
        Sanctum::actingAs($fixture['admin']);
        $branch = $fixture['baq']->id;
        $period = 'date_from=2026-08-01&date_to=2026-08-24&branch_id='.$branch;
        $cases = [
            'sales/export?'.$period => ['Ventas', $fixture['baqOrder']->order_number, $fixture['bogOrder']->order_number],
            'products/export?'.$period => ['Productos', "'=2+2", 'Producto BOG'],
            'payments/export?'.$period => ['Pagos', $fixture['baqOrder']->order_number, $fixture['bogOrder']->order_number],
            'receivables/export?'.$period => ['Cartera', $fixture['baqOrder']->order_number, $fixture['bogOrder']->order_number],
            'inventory/export?branch_id='.$branch => ['Inventario', 'Barranquilla', 'Bogotá'],
            'inventory-movements/export?'.$period => ['Movimientos de inventario', 'Movimiento BAQ', 'Movimiento BOG'],
            'quotations/export?'.$period => ['Cotizaciones', $fixture['baqQuote']->quotation_number, $fixture['bogQuote']->quotation_number],
            'customers/export?'.$period => ['Clientes', 'Cliente BAQ', 'Cliente BOG'],
        ];

        foreach ($cases as $endpoint => [$title, $included, $excluded]) {
            [$rows, $response, $path] = $this->xlsx('/api/admin/reports/'.$endpoint);
            $flat = collect($rows)->flatten()->map(fn ($value) => (string) $value)->all();
            $this->assertContains('Upgrade La 79', $flat, $endpoint);
            $this->assertContains($title, $flat, $endpoint);
            $this->assertContains($included, $flat, $endpoint);
            $this->assertNotContains($excluded, $flat, $endpoint);
            $this->assertStringContainsString('.xlsx', (string) $response->headers->get('content-disposition'));
            $this->cleanupResponse($response, $path);
        }
    }

    public function test_xlsx_content_disposition_is_exposed_to_the_configured_frontend_origin(): void
    {
        $fixture = $this->fixture();
        Sanctum::actingAs($fixture['admin']);

        $testResponse = $this
            ->withHeader('Origin', (string) config('cors.allowed_origins.0'))
            ->get('/api/admin/reports/sales/export?date_from=2026-08-01&date_to=2026-08-24')
            ->assertOk()
            ->assertHeader('Access-Control-Expose-Headers', 'Content-Disposition');

        $response = $testResponse->baseResponse;
        $this->assertInstanceOf(BinaryFileResponse::class, $response);
        $this->assertStringContainsString('.xlsx', (string) $response->headers->get('Content-Disposition'));
        $this->cleanupResponse($response, $response->getFile()->getPathname());
    }

    public function test_export_ignores_pagination_and_includes_all_filtered_rows(): void
    {
        $fixture = $this->fixture();
        Sanctum::actingAs($fixture['admin']);
        $second = $this->order($fixture['baq'], 'BAQ-SECOND', 50000, $fixture['baqCustomer']);
        $this->item($second, $fixture['baqProduct'], 1, 50000);

        [$rows, $response, $path] = $this->xlsx('/api/admin/reports/sales/export?date_from=2026-08-01&date_to=2026-08-24&branch_id='.$fixture['baq']->id.'&page=2&per_page=1');
        $flat = collect($rows)->flatten()->map(fn ($value) => (string) $value)->all();
        $this->assertContains($fixture['baqOrder']->order_number, $flat);
        $this->assertContains($second->order_number, $flat);
        $this->cleanupResponse($response, $path);
    }

    public function test_general_export_has_business_metadata_bogota_timezone_safe_filename_and_sheet_name(): void
    {
        $fixture = $this->fixture();
        Sanctum::actingAs($fixture['admin']);

        [$rows, $response, $path, $sheetName] = $this->xlsx('/api/admin/reports/sales/export?date_from=2026-08-01&date_to=2026-08-24');

        $this->assertSame(['Empresa', 'Upgrade La 79'], array_slice($rows[0], 0, 2));
        $this->assertSame(['Reporte', 'Ventas'], array_slice($rows[1], 0, 2));
        $this->assertSame(['Sede', 'General'], array_slice($rows[2], 0, 2));
        $this->assertSame(['Período', '2026-08-01 - 2026-08-24'], array_slice($rows[3], 0, 2));
        $this->assertSame(['Generado en', '2026-08-24 10:00:00 America/Bogota'], array_slice($rows[4], 0, 2));
        $this->assertSame('Ventas', $sheetName);
        $this->assertStringContainsString('ventas_general_2026-08-01_2026-08-24.xlsx', (string) $response->headers->get('content-disposition'));
        $this->cleanupResponse($response, $path);
    }

    public function test_xlsx_financial_columns_are_backend_permission_aware(): void
    {
        $fixture = $this->fixture();
        $reportsOnly = new class extends User
        {
            public function permissions(): array
            {
                return ['reports.view'];
            }
        };
        $reportsOnly->setTable('users');
        $reportsOnly->forceFill([
            'name' => 'Restricted reports user',
            'email' => 'restricted-reports@example.test',
            'password' => 'password',
            'role' => User::ROLE_USER,
            'is_active' => true,
        ])->save();
        Sanctum::actingAs($reportsOnly);
        [$restrictedRows, $restrictedResponse, $restrictedPath] = $this->xlsx('/api/admin/reports/products/export?date_from=2026-08-01&date_to=2026-08-24&branch_id='.$fixture['baq']->id);
        $restrictedHeaders = collect($restrictedRows)->first(fn (array $row) => in_array('Producto', $row, true));
        $this->assertNotContains('COGS', $restrictedHeaders);
        $this->assertNotContains('Utilidad conocida', $restrictedHeaders);
        $this->cleanupResponse($restrictedResponse, $restrictedPath);

        Sanctum::actingAs($fixture['admin']);
        [$adminRows, $adminResponse, $adminPath] = $this->xlsx('/api/admin/reports/products/export?date_from=2026-08-01&date_to=2026-08-24&branch_id='.$fixture['baq']->id);
        $adminHeaders = collect($adminRows)->first(fn (array $row) => in_array('Producto', $row, true));
        $this->assertContains('COGS', $adminHeaders);
        $this->assertContains('Utilidad conocida', $adminHeaders);
        $this->cleanupResponse($adminResponse, $adminPath);
    }

    public function test_customers_export_does_not_add_private_fields_absent_from_json_report(): void
    {
        $fixture = $this->fixture();
        Sanctum::actingAs($fixture['admin']);

        [$rows, $response, $path] = $this->xlsx('/api/admin/reports/customers/export?date_from=2026-08-01&date_to=2026-08-24');
        $headers = collect($rows)->first(fn (array $row) => in_array('Cliente', $row, true));
        $flat = collect($rows)->flatten()->map(fn ($value) => (string) $value)->all();

        $this->assertNotContains('Teléfono', $headers);
        $this->assertNotContains('Email', $headers);
        $this->assertNotContains('baq@example.com', $flat);
        $this->assertNotContains('bog@example.com', $flat);
        $this->cleanupResponse($response, $path);
    }

    public function test_formula_injection_helper_neutralizes_all_dangerous_prefixes_without_changing_numbers(): void
    {
        $service = app(ReportExportService::class);
        foreach (['=2+2', '+SUM(A1:A2)', '-1+2', '@cmd'] as $value) {
            $this->assertSame("'".$value, $service->neutralizeFormulaValue($value));
        }
        $this->assertSame(250000, $service->neutralizeFormulaValue(250000));
    }

    private function fixture(): array
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
        $baq = Branch::create(['code' => 'BAQ', 'slug' => 'barranquilla', 'name' => 'Barranquilla', 'city' => 'Barranquilla', 'is_active' => true]);
        $bog = Branch::create(['code' => 'BOG', 'slug' => 'bogota', 'name' => 'Bogotá', 'city' => 'Bogotá', 'is_active' => true]);
        $baqCustomer = Customer::create(['name' => 'Cliente BAQ', 'email' => 'baq@example.com', 'is_active' => true]);
        $bogCustomer = Customer::create(['name' => 'Cliente BOG', 'email' => 'bog@example.com', 'is_active' => true]);
        $baqProduct = $this->product('Producto BAQ', '=2+2');
        $bogProduct = $this->product('Producto BOG', 'BOG-SKU');
        $baqItem = InventoryItem::create(['product_id' => $baqProduct->id]);
        $bogItem = InventoryItem::create(['product_id' => $bogProduct->id]);
        InventoryStock::create(['branch_id' => $baq->id, 'inventory_item_id' => $baqItem->id, 'quantity' => 4, 'minimum_quantity' => 2]);
        InventoryStock::create(['branch_id' => $bog->id, 'inventory_item_id' => $bogItem->id, 'quantity' => 8, 'minimum_quantity' => 2]);
        $baqOrder = $this->order($baq, 'BAQ-ORDER', 100000, $baqCustomer);
        $bogOrder = $this->order($bog, 'BOG-ORDER', 200000, $bogCustomer);
        $this->item($baqOrder, $baqProduct, 1, 100000);
        $this->item($bogOrder, $bogProduct, 2, 100000);
        Payment::create(['order_id' => $baqOrder->id, 'amount' => 40000, 'method' => Payment::METHOD_TRANSFER, 'status' => Payment::STATUS_COMPLETED, 'reference' => '=PAY', 'paid_at' => '2026-08-24 13:00:00']);
        Payment::create(['order_id' => $bogOrder->id, 'amount' => 200000, 'method' => Payment::METHOD_CASH, 'status' => Payment::STATUS_COMPLETED, 'paid_at' => '2026-08-24 13:00:00']);
        InventoryMovement::create(['branch_id' => $baq->id, 'inventory_item_id' => $baqItem->id, 'product_id' => $baqProduct->id, 'type' => InventoryMovement::TYPE_ENTRY, 'quantity_delta' => 4, 'stock_before' => 0, 'stock_after' => 4, 'reason' => 'Movimiento BAQ', 'created_by' => $admin->id, 'created_at' => '2026-08-24 12:00:00']);
        InventoryMovement::create(['branch_id' => $bog->id, 'inventory_item_id' => $bogItem->id, 'product_id' => $bogProduct->id, 'type' => InventoryMovement::TYPE_ENTRY, 'quantity_delta' => 8, 'stock_before' => 0, 'stock_after' => 8, 'reason' => 'Movimiento BOG', 'created_by' => $admin->id, 'created_at' => '2026-08-24 12:00:00']);
        $baqQuote = $this->quotation($baq, 'BAQ-QUOTE', $baqCustomer);
        $bogQuote = $this->quotation($bog, 'BOG-QUOTE', $bogCustomer);

        return compact('admin', 'baq', 'bog', 'baqCustomer', 'bogCustomer', 'baqProduct', 'bogProduct', 'baqOrder', 'bogOrder', 'baqQuote', 'bogQuote');
    }

    private function product(string $name, string $sku): Product
    {
        return Product::create(['name' => $name, 'slug' => 'product-'.uniqid(), 'sku' => $sku, 'price' => 100000, 'total_cost' => 40000, 'is_active' => true, 'is_visible' => true]);
    }

    private function order(Branch $branch, string $number, int $total, Customer $customer): Order
    {
        return Order::create(['order_number' => $number, 'branch_id' => $branch->id, 'origin' => Order::ORIGIN_CRM, 'customer_id' => $customer->id, 'customer_name' => $customer->name, 'subtotal' => $total, 'discount_total' => 0, 'total' => $total, 'status' => Order::STATUS_CONFIRMED, 'payment_status' => Order::PAYMENT_PARTIAL, 'confirmed_at' => '2026-08-24 12:00:00']);
    }

    private function item(Order $order, Product $product, int $quantity, int $price): void
    {
        OrderItem::create(['order_id' => $order->id, 'item_type' => OrderItem::ITEM_TYPE_PRODUCT, 'product_id' => $product->id, 'product_name' => $product->name, 'product_sku' => $product->sku, 'unit_price' => $price, 'unit_cost' => 40000, 'quantity' => $quantity, 'subtotal' => $price * $quantity, 'discount_amount' => 0, 'total' => $price * $quantity]);
    }

    private function quotation(Branch $branch, string $number, Customer $customer): Quotation
    {
        return Quotation::create(['quotation_number' => $number, 'branch_id' => $branch->id, 'status' => Quotation::STATUS_SENT, 'valid_until' => '2026-08-25', 'customer_id' => $customer->id, 'customer_name' => $customer->name, 'subtotal' => 100000, 'discount_total' => 0, 'total' => 100000]);
    }

    private function xlsx(string $url): array
    {
        $testResponse = $this->get($url)->assertOk()->assertHeader('content-type', ReportExportService::MIME);
        $response = $testResponse->baseResponse;
        $this->assertInstanceOf(BinaryFileResponse::class, $response);
        $path = $response->getFile()->getPathname();
        $this->assertFileExists($path);
        $reader = new Reader;
        $reader->open($path);
        $rows = [];
        $sheetName = null;
        foreach ($reader->getSheetIterator() as $sheet) {
            $sheetName = $sheet->getName();
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
            break;
        }
        $reader->close();

        return [$rows, $response, $path, $sheetName];
    }

    private function cleanupResponse(BinaryFileResponse $response, string $path): void
    {
        ob_start();
        $response->sendContent();
        ob_end_clean();
        $this->assertFileDoesNotExist($path);
    }
}
