<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreQuotationRequest;
use App\Http\Requests\UpdateQuotationRequest;
use App\Http\Requests\UpdateQuotationStatusRequest;
use App\Models\Quotation;
use App\Services\QuotationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class QuotationController extends Controller
{
    public function __construct(private readonly QuotationService $quotations) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'quotations.view');
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:160'],
            'status' => ['nullable', Rule::in(Quotation::statuses())],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'created_by' => ['nullable', 'integer', 'exists:users,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'sales_employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $this->quotations->materializeExpired();
        $query = Quotation::query()->with([
            'customer',
            'customerVehicle',
            'creator',
            'branch:id,code,name,city,is_active',
            'salesEmployee:id,branch_id,name,is_active',
            'salesEmployee.branch:id,code,name,city,is_active',
        ])->withCount('items');
        $results = $this->quotations->applyFilters($query, $filters)
            ->latest()
            ->latest('id')
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->withQueryString();

        return response()->json(['data' => $results]);
    }

    public function store(StoreQuotationRequest $request): JsonResponse
    {
        $quotation = $this->quotations->create($request->validated(), $request->user());

        return response()->json(['message' => 'Cotización creada correctamente.', 'data' => $quotation], 201);
    }

    public function show(Request $request, Quotation $quotation): JsonResponse
    {
        $this->authorizePermission($request, 'quotations.view');
        $this->quotations->expireIfNeeded($quotation, $request->user());

        return response()->json(['data' => $this->quotations->load($quotation->fresh())]);
    }

    public function update(UpdateQuotationRequest $request, Quotation $quotation): JsonResponse
    {
        $quotation = $this->quotations->update($quotation, $request->validated(), $request->user());

        return response()->json(['message' => 'Cotización actualizada correctamente.', 'data' => $quotation]);
    }

    public function updateStatus(UpdateQuotationStatusRequest $request, Quotation $quotation): JsonResponse
    {
        $quotation = $this->quotations->transition(
            $quotation,
            $request->validated('status'),
            $request->user(),
            $request->validated('reason'),
        );

        return response()->json(['message' => 'Estado actualizado correctamente.', 'data' => $quotation]);
    }

    public function convert(Request $request, Quotation $quotation): JsonResponse
    {
        $this->authorizePermission($request, 'quotations.convert');
        $quotation = $this->quotations->convert($quotation, $request->user());

        return response()->json(['message' => 'Cotización convertida a venta correctamente.', 'data' => $quotation], 201);
    }

    private function authorizePermission(Request $request, string $permission): void
    {
        abort_unless($request->user()?->hasPermission($permission), 403, 'No tienes permisos para realizar esta acción.');
    }
}
