<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCustomerVehicleRequest;
use App\Http\Requests\UpdateCustomerVehicleRequest;
use App\Models\Customer;
use App\Models\CustomerVehicle;
use App\Services\CustomerVehicleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerVehicleController extends Controller
{
    public function __construct(private readonly CustomerVehicleService $vehicles) {}

    public function index(Request $request, Customer $customer): JsonResponse
    {
        $this->authorizeView($request);
        $vehicles = $customer->vehicles()->with(['vehicleBrand', 'vehicleModel', 'vehicleVersion'])->latest()->get();

        return response()->json(['data' => $vehicles]);
    }

    public function store(StoreCustomerVehicleRequest $request, Customer $customer): JsonResponse
    {
        $vehicle = $this->vehicles->create($customer, $request->validated(), $request->user())
            ->load(['vehicleBrand', 'vehicleModel', 'vehicleVersion']);

        return response()->json(['message' => 'Vehículo creado correctamente.', 'data' => $vehicle], 201);
    }

    public function show(Request $request, Customer $customer, CustomerVehicle $vehicle): JsonResponse
    {
        $this->authorizeView($request);
        $this->ensureOwnership($customer, $vehicle);

        return response()->json(['data' => $vehicle->load(['vehicleBrand', 'vehicleModel', 'vehicleVersion'])]);
    }

    public function update(UpdateCustomerVehicleRequest $request, Customer $customer, CustomerVehicle $vehicle): JsonResponse
    {
        $this->ensureOwnership($customer, $vehicle);
        $vehicle = $this->vehicles->update($vehicle, $request->validated(), $request->user());

        return response()->json(['message' => 'Vehículo actualizado correctamente.', 'data' => $vehicle]);
    }

    public function orders(Request $request, Customer $customer, CustomerVehicle $vehicle): JsonResponse
    {
        $this->authorizeView($request);
        $this->ensureOwnership($customer, $vehicle);
        $perPage = min(max($request->integer('per_page', 20), 1), 100);
        $orders = $vehicle->orders()->with(['items.productVariant', 'payments'])->latest()->paginate($perPage)->withQueryString();

        return response()->json(['data' => $orders]);
    }

    private function authorizeView(Request $request): void
    {
        abort_unless($request->user()?->hasPermission('customers.view'), 403, 'No tienes permisos para realizar esta acción.');
    }

    private function ensureOwnership(Customer $customer, CustomerVehicle $vehicle): void
    {
        abort_unless((int) $vehicle->customer_id === $customer->id, 404);
    }
}
