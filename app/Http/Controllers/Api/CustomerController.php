<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Models\Customer;
use App\Services\CustomerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function __construct(private readonly CustomerService $customers) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeView($request);
        $perPage = min(max($request->integer('per_page', 15), 1), 100);

        return response()->json(['data' => $this->customers->search($request->string('search')->toString(), $perPage)]);
    }

    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $customer = $this->customers->create($request->validated(), $request->user());

        return response()->json(['message' => 'Cliente creado correctamente.', 'data' => $customer], 201);
    }

    public function show(Request $request, Customer $customer): JsonResponse
    {
        $this->authorizeView($request);

        return response()->json(['data' => $this->customers->detail($customer)]);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): JsonResponse
    {
        $customer = $this->customers->update($customer, $request->validated(), $request->user());

        return response()->json(['message' => 'Cliente actualizado correctamente.', 'data' => $customer]);
    }

    public function orders(Request $request, Customer $customer): JsonResponse
    {
        $this->authorizeView($request);
        $perPage = min(max($request->integer('per_page', 20), 1), 100);
        $orders = $customer->orders()->with(['items.productVariant', 'items.service.category', 'payments'])->latest()->paginate($perPage)->withQueryString();

        return response()->json(['data' => $orders]);
    }

    private function authorizeView(Request $request): void
    {
        abort_unless($request->user()?->hasPermission('customers.view'), 403, 'No tienes permisos para realizar esta acción.');
    }
}
