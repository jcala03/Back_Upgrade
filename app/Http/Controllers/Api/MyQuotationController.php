<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListMyQuotationsRequest;
use App\Http\Requests\StoreMyQuotationRequest;
use App\Http\Requests\UpdateMyQuotationRequest;
use App\Http\Resources\MyQuotationResource;
use App\Models\Quotation;
use App\Services\QuotationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MyQuotationController extends Controller
{
    public function __construct(private readonly QuotationService $quotations) {}

    public function index(ListMyQuotationsRequest $request): JsonResponse
    {
        $employee = $request->user()->employee;
        if (! $employee) {
            return response()->json([
                'data' => ['employee_linked' => false, 'quotations' => []],
            ]);
        }

        $filters = $request->validated();
        $this->quotations->materializeExpiredForEmployee($employee);
        $query = Quotation::query()
            ->where('sales_employee_id', $employee->id)
            ->with([
                'branch:id,code,name,city,is_active',
                'salesEmployee:id,branch_id,name,is_active',
            ])
            ->withCount('items');
        $results = $this->quotations->applyPersonalFilters($query, $filters)
            ->latest()
            ->latest('id')
            ->paginate((int) ($filters['per_page'] ?? 25))
            ->withQueryString();
        $results->setCollection(
            $results->getCollection()
                ->map(fn (Quotation $quotation) => (new MyQuotationResource($quotation))->resolve($request)),
        );

        return response()->json([
            'data' => ['employee_linked' => true, 'quotations' => $results],
        ]);
    }

    public function store(StoreMyQuotationRequest $request): JsonResponse
    {
        $quotation = $this->quotations->createPersonal($request->validated(), $request->user());

        return response()->json([
            'message' => 'Cotización creada correctamente.',
            'data' => (new MyQuotationResource($quotation))->resolve($request),
        ], 201);
    }

    public function show(Request $request, Quotation $quotation): JsonResponse
    {
        $this->authorizePermission($request, 'quotations.view_own');
        $this->own($request, $quotation);
        $this->quotations->expireIfNeeded($quotation, $request->user());

        return response()->json([
            'data' => (new MyQuotationResource(
                $this->quotations->loadPersonal($quotation->fresh()),
            ))->resolve($request),
        ]);
    }

    public function update(UpdateMyQuotationRequest $request, Quotation $quotation): JsonResponse
    {
        $this->own($request, $quotation);
        $quotation = $this->quotations->updatePersonal(
            $quotation,
            $request->validated(),
            $request->user(),
        );

        return response()->json([
            'message' => 'Cotización actualizada correctamente.',
            'data' => (new MyQuotationResource($quotation))->resolve($request),
        ]);
    }

    public function send(Request $request, Quotation $quotation): JsonResponse
    {
        $this->authorizePermission($request, 'quotations.send_own');
        $request->validate($this->actionRules());
        $this->own($request, $quotation);
        $quotation = $this->quotations->sendPersonal($quotation, $request->user());

        return response()->json([
            'message' => 'Cotización enviada correctamente.',
            'data' => (new MyQuotationResource($quotation))->resolve($request),
        ]);
    }

    public function convert(Request $request, Quotation $quotation): JsonResponse
    {
        $this->authorizePermission($request, 'quotations.convert_own');
        $request->validate($this->actionRules());
        $this->own($request, $quotation);
        $quotation = $this->quotations->convertPersonal($quotation, $request->user());

        return response()->json([
            'message' => 'Cotización convertida a venta correctamente.',
            'data' => (new MyQuotationResource($quotation))->resolve($request),
        ], 201);
    }

    private function own(Request $request, Quotation $quotation): void
    {
        $employeeId = $request->user()->employee?->id;

        abort_unless(
            $employeeId !== null && $employeeId === $quotation->sales_employee_id,
            404,
        );
    }

    private function authorizePermission(Request $request, string $permission): void
    {
        abort_unless(
            $request->user()?->hasPermission($permission),
            403,
            'No tienes permisos para realizar esta acción.',
        );
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function actionRules(): array
    {
        return [
            'branch_id' => ['prohibited'],
            'sales_employee_id' => ['prohibited'],
            'created_by' => ['prohibited'],
            'updated_by' => ['prohibited'],
            'converted_by' => ['prohibited'],
            'status' => ['prohibited'],
            'order_id' => ['prohibited'],
        ];
    }
}
