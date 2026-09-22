<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\EmployeeAvailabilityRequest;
use App\Models\Employee;
use App\Services\EmployeeAvailabilityService;
use App\Support\Business\BusinessContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

class EmployeeAvailabilityController extends Controller
{
    public function __construct(private readonly EmployeeAvailabilityService $service) {}

    public function index(EmployeeAvailabilityRequest $request): JsonResponse
    {
        $data = $request->validated();
        $startsAt = CarbonImmutable::parse($data['starts_at'], BusinessContext::TIMEZONE)->utc();
        $endsAt = CarbonImmutable::parse($data['ends_at'], BusinessContext::TIMEZONE)->utc();
        $idsWereProvided = array_key_exists('employee_ids', $data);
        $employees = Employee::query()
            ->select(['id', 'name', 'job_title', 'specialty', 'is_active'])
            ->when($idsWereProvided, fn ($query) => $query->whereKey($data['employee_ids']))
            ->when(! $idsWereProvided, fn ($query) => $query->active())
            ->orderBy('name')->orderBy('id')->limit(100)->get();
        $results = $this->service->availableEmployees($employees, $startsAt, $endsAt);

        return response()->json(['data' => $employees->map(function (Employee $employee) use ($results) {
            return [
                'employee' => $employee->only(['id', 'name', 'job_title', 'specialty']),
                ...$results->get($employee->id)->toArray(),
            ];
        })->values()]);
    }
}
