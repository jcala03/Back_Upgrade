<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CancelAppointmentRequest;
use App\Http\Requests\ChangeAppointmentStatusRequest;
use App\Http\Requests\RescheduleAppointmentRequest;
use App\Http\Requests\StoreAppointmentRequest;
use App\Http\Requests\UpdateAppointmentRequest;
use App\Models\Appointment;
use App\Services\AppointmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AppointmentController extends Controller
{
    public function __construct(private readonly AppointmentService $service) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('appointments.view'), 403);
        $filters = $request->validate(['status' => ['nullable', Rule::in(Appointment::statuses())], 'source' => ['nullable', Rule::in(Appointment::sources())],
            'responsible_employee_id' => ['nullable', 'integer', 'exists:employees,id'], 'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'customer_vehicle_id' => ['nullable', 'integer', 'exists:customer_vehicles,id'], 'service_id' => ['nullable', 'integer', 'exists:services,id'],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'search' => ['nullable', 'string', 'max:180'],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);

        return response()->json(['data' => $this->service->paginate($filters)]);
    }

    public function store(StoreAppointmentRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->service->create($request->validated(), $request->user())], 201);
    }

    public function show(Request $request, Appointment $appointment): JsonResponse
    {
        abort_unless($request->user()->hasPermission('appointments.view'), 403);

        return response()->json(['data' => $this->service->load($appointment)]);
    }

    public function update(UpdateAppointmentRequest $request, Appointment $appointment): JsonResponse
    {
        return response()->json(['data' => $this->service->update($appointment, $request->validated(), $request->user())]);
    }

    public function status(ChangeAppointmentStatusRequest $request, Appointment $appointment): JsonResponse
    {
        return response()->json(['data' => $this->service->changeStatus($appointment, $request->validated(), $request->user())]);
    }

    public function reschedule(RescheduleAppointmentRequest $request, Appointment $appointment): JsonResponse
    {
        return response()->json(['data' => $this->service->reschedule($appointment, $request->validated(), $request->user())]);
    }

    public function cancel(CancelAppointmentRequest $request, Appointment $appointment): JsonResponse
    {
        return response()->json(['data' => $this->service->cancel($appointment, $request->validated('cancellation_reason'), $request->user())]);
    }
}
