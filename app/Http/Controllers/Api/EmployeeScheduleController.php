<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEmployeeScheduleRequest;
use App\Http\Requests\UpdateEmployeeScheduleRequest;
use App\Models\EmployeeWorkSchedule;
use App\Services\EmployeeScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeScheduleController extends Controller
{
    public function __construct(private readonly EmployeeScheduleService $service) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'employee_schedules.view');
        $filters = $request->validate([
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'day_of_week' => ['nullable', 'integer', 'between:1,7'],
            'effective_on' => ['nullable', 'date_format:Y-m-d'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $schedules = EmployeeWorkSchedule::query()
            ->with('employee:id,name,is_active')
            ->when($filters['employee_id'] ?? null, fn ($query, $id) => $query->where('employee_id', $id))
            ->when($filters['day_of_week'] ?? null, fn ($query, $day) => $query->where('day_of_week', $day))
            ->when($filters['effective_on'] ?? null, fn ($query, $date) => $query->effectiveOn($date))
            ->orderBy('employee_id')->orderBy('day_of_week')->orderBy('starts_at')->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 25))->withQueryString();

        return response()->json(['data' => $schedules]);
    }

    public function store(StoreEmployeeScheduleRequest $request): JsonResponse
    {
        return response()->json(['message' => 'Horario creado correctamente.', 'data' => $this->service->createSchedule($request->validated(), $request->user())], 201);
    }

    public function update(UpdateEmployeeScheduleRequest $request, EmployeeWorkSchedule $schedule): JsonResponse
    {
        return response()->json(['message' => 'Horario actualizado correctamente.', 'data' => $this->service->updateSchedule($schedule, $request->validated())]);
    }

    public function destroy(Request $request, EmployeeWorkSchedule $schedule): JsonResponse
    {
        $this->authorizePermission($request, 'employee_schedules.update');
        $this->service->deleteSchedule($schedule);

        return response()->json(['message' => 'Horario eliminado correctamente.']);
    }

    private function authorizePermission(Request $request, string $permission): void
    {
        abort_unless($request->user()?->hasPermission($permission), 403, 'No tienes permisos para realizar esta acción.');
    }
}
