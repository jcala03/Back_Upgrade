<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEmployeeScheduleOverrideRequest;
use App\Http\Requests\UpdateEmployeeScheduleOverrideRequest;
use App\Models\EmployeeScheduleOverride;
use App\Services\EmployeeScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeScheduleOverrideController extends Controller
{
    public function __construct(private readonly EmployeeScheduleService $service) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'employee_schedules.view');
        $filters = $request->validate([
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $overrides = EmployeeScheduleOverride::query()
            ->with('employee:id,name,is_active')
            ->when($filters['employee_id'] ?? null, fn ($query, $id) => $query->where('employee_id', $id))
            ->when($filters['date'] ?? null, fn ($query, $date) => $query->onDate($date))
            ->when($filters['from'] ?? null, fn ($query, $date) => $query->whereDate('date', '>=', $date))
            ->when($filters['to'] ?? null, fn ($query, $date) => $query->whereDate('date', '<=', $date))
            ->orderBy('date')->orderBy('employee_id')->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 25))->withQueryString();

        return response()->json(['data' => $overrides]);
    }

    public function store(StoreEmployeeScheduleOverrideRequest $request): JsonResponse
    {
        return response()->json(['message' => 'Excepción de horario creada correctamente.', 'data' => $this->service->createOverride($request->validated(), $request->user())], 201);
    }

    public function update(UpdateEmployeeScheduleOverrideRequest $request, EmployeeScheduleOverride $override): JsonResponse
    {
        return response()->json(['message' => 'Excepción de horario actualizada correctamente.', 'data' => $this->service->updateOverride($override, $request->validated())]);
    }

    public function destroy(Request $request, EmployeeScheduleOverride $override): JsonResponse
    {
        $this->authorizePermission($request, 'employee_schedules.update');
        $this->service->deleteOverride($override);

        return response()->json(['message' => 'Excepción de horario eliminada correctamente.']);
    }

    private function authorizePermission(Request $request, string $permission): void
    {
        abort_unless($request->user()?->hasPermission($permission), 403, 'No tienes permisos para realizar esta acción.');
    }
}
