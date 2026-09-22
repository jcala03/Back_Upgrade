<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerVehicle;
use App\Models\Employee;
use App\Models\Order;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Models\UserCapability;
use App\Models\VehicleBrand;
use App\Models\VehicleModel;
use App\Models\VehicleVersion;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class PersonalCommercialDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    public function createApplication()
    {
        $connection = trim((string) getenv('TEST_DB_CONNECTION'));
        $database = trim((string) getenv('TEST_DB_DATABASE'));
        if ($connection !== 'mysql' || $database === '' || strcasecmp($database, 'upgrade') === 0) {
            throw new RuntimeException('PersonalCommercialDiscoveryTest requiere MySQL temporal explícita distinta de upgrade.');
        }

        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $app['config']->set('database.default', $connection);
        $app['config']->set("database.connections.{$connection}.database", $database);

        return $app;
    }

    public function test_discovery_requires_auth_capability_and_operational_employee(): void
    {
        $this->getJson('/api/my/customer-options?search=Ana')->assertUnauthorized();
        $this->getJson('/api/my/service-options')->assertUnauthorized();

        $user = User::factory()->create(['role' => User::ROLE_USER, 'is_active' => true]);
        Sanctum::actingAs($user);
        $this->getJson('/api/my/customer-options?search=Ana')->assertForbidden();
        $this->getJson('/api/my/service-options')->assertForbidden();

        $this->grant($user, UserCapability::ORDERS_CREATE_OWN);
        $this->getJson('/api/my/customer-options?search=Ana')
            ->assertUnprocessable()->assertJsonValidationErrors('employee');
        $this->getJson('/api/my/service-options')
            ->assertUnprocessable()->assertJsonValidationErrors('employee');

        $branch = $this->branch();
        $employee = $this->employee($branch, $user);
        $employee->update(['is_active' => false]);
        $this->getJson('/api/my/customer-options?search=Ana')
            ->assertUnprocessable()->assertJsonValidationErrors('employee');
    }

    public function test_any_relevant_capability_grants_both_discovery_endpoints(): void
    {
        $branch = $this->branch();

        foreach ([
            UserCapability::QUOTATIONS_CREATE_OWN,
            UserCapability::QUOTATIONS_UPDATE_OWN,
            UserCapability::ORDERS_CREATE_OWN,
        ] as $capability) {
            $user = User::factory()->create(['role' => User::ROLE_USER, 'is_active' => true]);
            $this->employee($branch, $user);
            $this->grant($user, $capability);
            Sanctum::actingAs($user);

            $this->getJson('/api/my/customer-options?search=ZZ')
                ->assertOk()->assertExactJson(['data' => []]);
            $this->getJson('/api/my/service-options')
                ->assertOk()->assertExactJson(['data' => []]);
        }
    }

    public function test_customer_search_is_required_limited_active_global_and_non_enumerable(): void
    {
        $this->actingCommercially(UserCapability::ORDERS_CREATE_OWN);
        $this->getJson('/api/my/customer-options')->assertUnprocessable()->assertJsonValidationErrors('search');
        $this->getJson('/api/my/customer-options?search=A')->assertUnprocessable()->assertJsonValidationErrors('search');

        foreach (range(1, 25) as $index) {
            Customer::create([
                'name' => sprintf('Cliente Global %02d', $index),
                'is_active' => true,
            ]);
        }
        Customer::create(['name' => 'Cliente Global Inactivo', 'is_active' => false]);

        $response = $this->getJson('/api/my/customer-options?search=Global')
            ->assertOk()
            ->assertJsonCount(20, 'data');
        $this->assertCount(20, array_unique(array_column($response->json('data'), 'id')));
        $this->assertNotContains('Cliente Global Inactivo', array_column($response->json('data'), 'name'));
    }

    public function test_customer_search_supports_identity_and_plate_with_reduced_eager_loaded_vehicles(): void
    {
        $this->actingCommercially(UserCapability::QUOTATIONS_CREATE_OWN);
        [$brand, $model, $version] = $this->vehicleCatalog();
        $customer = Customer::create([
            'name' => 'Paola Comercial',
            'phone' => '3001237890',
            'phone_normalized' => '3001237890',
            'email' => 'paola@example.com',
            'document' => 'CC123456',
            'city' => 'Barranquilla',
            'address' => 'Privada 123',
            'notes' => 'Nota privada',
            'is_active' => true,
        ]);
        $vehicle = CustomerVehicle::create([
            'customer_id' => $customer->id,
            'vehicle_brand_id' => $brand->id,
            'vehicle_model_id' => $model->id,
            'vehicle_version_id' => $version->id,
            'year' => 2023,
            'plate' => 'ABC123',
            'vin' => 'VIN-NO-EXPONER',
            'color' => 'Negro',
            'nickname' => 'BMW diario',
            'notes' => 'Vehículo privado',
            'is_active' => true,
        ]);
        CustomerVehicle::create([
            'customer_id' => $customer->id,
            'plate' => 'INA999',
            'vin' => 'VIN-INACTIVO',
            'is_active' => false,
        ]);

        foreach (['Paola', '1237890', 'paola@example', 'CC123456', 'ABC123'] as $search) {
            $this->getJson('/api/my/customer-options?search='.urlencode($search))
                ->assertOk()->assertJsonPath('data.0.id', $customer->id);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->getJson('/api/my/customer-options?search=ABC123')->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response
            ->assertJsonPath('data.0.contact.phone', '••••••7890')
            ->assertJsonPath('data.0.contact.email', 'p•••@example.com')
            ->assertJsonPath('data.0.contact.document', '••••3456')
            ->assertJsonPath('data.0.vehicles.0.id', $vehicle->id)
            ->assertJsonPath('data.0.vehicles.0.display_name', 'BMW diario')
            ->assertJsonPath('data.0.vehicles.0.brand.name', 'BMW')
            ->assertJsonPath('data.0.vehicles.0.model.name', 'Serie 3')
            ->assertJsonPath('data.0.vehicles.0.version.name', '330i')
            ->assertJsonPath('data.0.vehicles.0.plate', 'ABC123')
            ->assertJsonCount(1, 'data.0.vehicles')
            ->assertJsonMissingPath('data.0.address')
            ->assertJsonMissingPath('data.0.notes')
            ->assertJsonMissingPath('data.0.created_by')
            ->assertJsonMissingPath('data.0.vehicles.0.vin')
            ->assertJsonMissingPath('data.0.vehicles.0.color')
            ->assertJsonMissingPath('data.0.vehicles.0.notes');
        $this->assertLessThanOrEqual(10, $queries, 'Customer discovery debe mantener consultas acotadas mediante eager loading.');
        $this->assertStringNotContainsString('VIN-NO-EXPONER', $response->getContent());
        $this->assertStringNotContainsString('Privada 123', $response->getContent());
    }

    public function test_service_options_are_limited_searchable_commercially_available_and_private(): void
    {
        $this->actingCommercially(UserCapability::QUOTATIONS_UPDATE_OWN);
        $activeCategory = $this->category('Instalación', true);
        $inactiveCategory = $this->category('Oculta', false);
        foreach (range(1, 25) as $index) {
            $this->service($activeCategory, sprintf('Servicio Comercial %02d', $index));
        }
        $inactive = $this->service($activeCategory, 'Servicio Comercial Inactivo', false);
        $hiddenByCategory = $this->service($inactiveCategory, 'Servicio Comercial Categoría Inactiva');

        $this->getJson('/api/my/service-options?search=S')->assertUnprocessable()->assertJsonValidationErrors('search');
        $response = $this->getJson('/api/my/service-options')->assertOk()->assertJsonCount(20, 'data');
        $ids = array_column($response->json('data'), 'id');
        $this->assertNotContains($inactive->id, $ids);
        $this->assertNotContains($hiddenByCategory->id, $ids);
        $response
            ->assertJsonPath('data.0.price', 80000)
            ->assertJsonPath('data.0.category.name', 'Instalación')
            ->assertJsonMissingPath('data.0.cost')
            ->assertJsonMissingPath('data.0.margin')
            ->assertJsonMissingPath('data.0.commission_amount')
            ->assertJsonMissingPath('data.0.sort_order')
            ->assertJsonMissingPath('data.0.is_active');
        $this->getJson('/api/my/service-options?search=Comercial%2025')
            ->assertOk()->assertJsonPath('data.0.name', 'Servicio Comercial 25');
    }

    public function test_my_quote_and_sale_associate_persistent_customer_vehicle_and_preserve_ad_hoc_flow(): void
    {
        [$user, $employee, $branch] = $this->actingCommercially([
            UserCapability::QUOTATIONS_CREATE_OWN,
            UserCapability::ORDERS_CREATE_OWN,
        ]);
        $category = $this->category();
        $service = $this->service($category, 'Diagnóstico');
        [$brand, $model, $version] = $this->vehicleCatalog();
        $customer = Customer::create([
            'name' => 'Cliente Persistente',
            'phone' => '3000000000',
            'email' => 'persistente@example.com',
            'is_active' => true,
        ]);
        $vehicle = CustomerVehicle::create([
            'customer_id' => $customer->id,
            'vehicle_brand_id' => $brand->id,
            'vehicle_model_id' => $model->id,
            'vehicle_version_id' => $version->id,
            'year' => 2023,
            'plate' => 'PER123',
            'is_active' => true,
        ]);
        Sanctum::actingAs($user);
        $items = [['item_type' => 'service', 'service_id' => $service->id, 'quantity' => 1]];

        $quotation = $this->postJson('/api/my/quotations', [
            'customer_id' => $customer->id,
            'customer_vehicle_id' => $vehicle->id,
            'customer_name' => 'Intento de spoof',
            'items' => $items,
        ])->assertCreated()
            ->assertJsonPath('data.customer.id', $customer->id)
            ->assertJsonPath('data.customer.name', 'Cliente Persistente')
            ->assertJsonPath('data.vehicle.customer_vehicle_id', $vehicle->id)
            ->assertJsonPath('data.branch.id', $branch->id)
            ->assertJsonPath('data.sales_employee.id', $employee->id);
        $this->assertDatabaseHas('quotations', [
            'id' => $quotation->json('data.id'),
            'customer_id' => $customer->id,
            'customer_name' => 'Cliente Persistente',
            'branch_id' => $branch->id,
            'sales_employee_id' => $employee->id,
        ]);

        $sale = $this->postJson('/api/my/sales', [
            'customer_id' => $customer->id,
            'customer_vehicle_id' => $vehicle->id,
            'customer_name' => 'Otro spoof',
            'items' => $items,
        ])->assertCreated()
            ->assertJsonPath('data.customer.id', $customer->id)
            ->assertJsonPath('data.customer.name', 'Cliente Persistente')
            ->assertJsonPath('data.vehicle.customer_vehicle_id', $vehicle->id)
            ->assertJsonPath('data.branch.id', $branch->id)
            ->assertJsonPath('data.sales_employee.id', $employee->id);
        $this->assertDatabaseHas('orders', [
            'id' => $sale->json('data.id'),
            'customer_id' => $customer->id,
            'customer_name' => 'Cliente Persistente',
            'branch_id' => $branch->id,
            'sales_employee_id' => $employee->id,
        ]);

        $this->postJson('/api/my/quotations', ['customer_name' => 'Mostrador Quote', 'items' => $items])
            ->assertCreated()->assertJsonPath('data.customer.id', null)->assertJsonPath('data.customer.name', 'Mostrador Quote');
        $this->postJson('/api/my/sales', ['customer_name' => 'Mostrador Sale', 'items' => $items])
            ->assertCreated()->assertJsonPath('data.customer.id', null)->assertJsonPath('data.customer.name', 'Mostrador Sale');
    }

    public function test_foreign_vehicle_is_rejected_and_vehicle_remains_optional(): void
    {
        [$user] = $this->actingCommercially([
            UserCapability::QUOTATIONS_CREATE_OWN,
            UserCapability::ORDERS_CREATE_OWN,
        ]);
        $service = $this->service($this->category(), 'Alineación');
        $customerA = Customer::create(['name' => 'Cliente A', 'is_active' => true]);
        $customerB = Customer::create(['name' => 'Cliente B', 'is_active' => true]);
        $vehicleB = CustomerVehicle::create(['customer_id' => $customerB->id, 'plate' => 'BBB222', 'is_active' => true]);
        $payload = [
            'customer_id' => $customerA->id,
            'customer_vehicle_id' => $vehicleB->id,
            'items' => [['item_type' => 'service', 'service_id' => $service->id, 'quantity' => 1]],
        ];
        Sanctum::actingAs($user);

        $this->postJson('/api/my/quotations', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('customer_vehicle_id');
        $this->postJson('/api/my/sales', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('customer_vehicle_id');

        unset($payload['customer_vehicle_id']);
        $this->postJson('/api/my/quotations', $payload)
            ->assertCreated()->assertJsonPath('data.vehicle.customer_vehicle_id', null);
        $this->postJson('/api/my/sales', $payload)
            ->assertCreated()->assertJsonPath('data.vehicle.customer_vehicle_id', null);
    }

    public function test_service_items_work_in_quote_update_and_sale_without_inventory_or_commission_side_effects(): void
    {
        [$user] = $this->actingCommercially([
            UserCapability::QUOTATIONS_CREATE_OWN,
            UserCapability::QUOTATIONS_UPDATE_OWN,
            UserCapability::ORDERS_CREATE_OWN,
            UserCapability::ORDERS_CONFIRM_OWN,
        ]);
        $service = $this->service($this->category(), 'Instalación premium');
        Sanctum::actingAs($user);

        $quotationId = $this->postJson('/api/my/quotations', [
            'items' => [['item_type' => 'service', 'service_id' => $service->id, 'quantity' => 1]],
        ])->assertCreated()
            ->assertJsonPath('data.items.0.unit_price', 80000)
            ->assertJsonPath('data.total', 80000)
            ->assertJsonMissingPath('data.items.0.unit_cost')
            ->json('data.id');
        $this->patchJson("/api/my/quotations/{$quotationId}", [
            'items' => [['item_type' => 'service', 'service_id' => $service->id, 'quantity' => 2]],
        ])->assertOk()
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.total', 160000);

        $orderId = $this->postJson('/api/my/sales', [
            'items' => [['item_type' => 'service', 'service_id' => $service->id, 'quantity' => 1]],
        ])->assertCreated()->assertJsonPath('data.total', 80000)->json('data.id');
        $this->postJson("/api/my/sales/{$orderId}/confirm")
            ->assertOk()->assertJsonPath('data.status', Order::STATUS_CONFIRMED);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('employee_commissions', 0);
    }

    /**
     * @param  string|array<int, string>  $capabilities
     * @return array{0: User, 1: Employee, 2: Branch}
     */
    private function actingCommercially(string|array $capabilities): array
    {
        $branch = $this->branch();
        $user = User::factory()->create(['role' => User::ROLE_USER, 'is_active' => true]);
        $employee = $this->employee($branch, $user);
        foreach ((array) $capabilities as $capability) {
            $this->grant($user, $capability);
        }
        Sanctum::actingAs($user);

        return [$user, $employee, $branch];
    }

    private function grant(User $user, string $capability): void
    {
        UserCapability::create(['user_id' => $user->id, 'capability' => $capability]);
        $user->unsetRelation('capabilities');
    }

    private function branch(string $code = 'BAQ'): Branch
    {
        return Branch::create([
            'code' => $code,
            'slug' => strtolower($code).'-'.uniqid(),
            'name' => "Sede {$code}",
            'city' => 'Barranquilla',
            'is_active' => true,
        ]);
    }

    private function employee(Branch $branch, User $user): Employee
    {
        return Employee::create([
            'user_id' => $user->id,
            'branch_id' => $branch->id,
            'name' => 'Asesor comercial',
            'job_title' => 'Asesor',
            'is_active' => true,
        ]);
    }

    private function category(string $name = 'Servicios', bool $active = true): ServiceCategory
    {
        return ServiceCategory::create([
            'name' => $name,
            'slug' => str($name)->slug().'-'.uniqid(),
            'is_active' => $active,
            'sort_order' => 0,
        ]);
    }

    private function service(ServiceCategory $category, string $name, bool $active = true): Service
    {
        return Service::create([
            'service_category_id' => $category->id,
            'name' => $name,
            'slug' => str($name)->slug().'-'.uniqid(),
            'description' => 'Descripción comercial',
            'price' => 80000,
            'cost' => 30000,
            'estimated_duration_minutes' => 60,
            'is_active' => $active,
            'sort_order' => 0,
        ]);
    }

    /** @return array{0: VehicleBrand, 1: VehicleModel, 2: VehicleVersion} */
    private function vehicleCatalog(): array
    {
        $brand = VehicleBrand::create(['name' => 'BMW', 'slug' => 'bmw-'.uniqid(), 'is_active' => true]);
        $model = VehicleModel::create([
            'vehicle_brand_id' => $brand->id,
            'name' => 'Serie 3',
            'slug' => 'serie-3-'.uniqid(),
            'is_active' => true,
        ]);
        $version = VehicleVersion::create([
            'vehicle_model_id' => $model->id,
            'name' => '330i',
            'year_from' => 2020,
            'year_to' => null,
            'is_active' => true,
        ]);

        return [$brand, $model, $version];
    }
}
