<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEmployeeLeaveRequest;
use App\Http\Requests\UpdateEmployeeLeaveRequest;
use App\Models\EmployeeLeave;
use App\Services\EmployeeLeaveService;
use App\Support\Business\BusinessContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmployeeLeaveController extends Controller
{
    public function __construct(private readonly EmployeeLeaveService $service) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'employee_leaves.view');
        $filters = $request->validate([
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'type' => ['nullable', Rule::in(EmployeeLeave::types())],
            'status' => ['nullable', Rule::in(EmployeeLeave::statuses())],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $from = isset($filters['from']) ? CarbonImmutable::parse($filters['from'], BusinessContext::TIMEZONE)->utc() : null;
        $to = isset($filters['to']) ? CarbonImmutable::parse($filters['to'], BusinessContext::TIMEZONE)->utc() : null;
        $leaves = EmployeeLeave::query()
            ->with(['employee:id,name,is_active', 'approver:id,name', 'canceller:id,name'])
            ->when($filters['employee_id'] ?? null, fn ($query, $id) => $query->where('employee_id', $id))
            ->when($filters['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($from, fn ($query) => $query->where('ends_at', '>', $from))
            ->when($to, fn ($query) => $query->where('starts_at', '<', $to))
            ->orderByDesc('starts_at')->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25))->withQueryString();

        return response()->json(['data' => $leaves]);
    }

    public function store(StoreEmployeeLeaveRequest $request): JsonResponse
    {
        return response()->json(['message' => 'Ausencia registrada correctamente.', 'data' => $this->service->create($request->validated(), $request->user())], 201);
    }

    public function show(Request $request, EmployeeLeave $leave): JsonResponse
    {
        $this->authorizePermission($request, 'employee_leaves.view');

        return response()->json(['data' => $leave->load(['employee:id,name,is_active', 'approver:id,name', 'canceller:id,name'])]);
    }

    public function update(UpdateEmployeeLeaveRequest $request, EmployeeLeave $leave): JsonResponse
    {
        return response()->json(['message' => 'Ausencia actualizada correctamente.', 'data' => $this->service->update($leave, $request->validated())]);
    }

    public function cancel(Request $request, EmployeeLeave $leave): JsonResponse
    {
        $this->authorizePermission($request, 'employee_leaves.cancel');

        return response()->json(['message' => 'Ausencia cancelada correctamente.', 'data' => $this->service->cancel($leave, $request->user())]);
    }

    private function authorizePermission(Request $request, string $permission): void
    {
        abort_unless($request->user()?->hasPermission($permission), 403, 'No tienes permisos para realizar esta acción.');
    }
}
