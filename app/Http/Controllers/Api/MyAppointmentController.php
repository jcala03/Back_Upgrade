<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Services\AppointmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MyAppointmentController extends Controller
{
    public function __construct(private readonly AppointmentService $service) {}

    public function index(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;
        if (! $employee) {
            return response()->json(['data' => ['employee_linked' => false, 'appointments' => []]]);
        }
        $filters = $request->validate(['status' => ['nullable', Rule::in(Appointment::statuses())], 'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $appointments = $this->service->paginate($filters, $employee);
        $appointments->getCollection()->each(fn (Appointment $appointment) => $this->sanitize($appointment));

        return response()->json(['data' => ['employee_linked' => true, 'appointments' => $appointments]]);
    }

    public function show(Request $request, Appointment $appointment): JsonResponse
    {
        abort_unless($request->user()->employee?->id === $appointment->responsible_employee_id, 404);

        return response()->json(['data' => $this->sanitize($appointment)]);
    }

    private function sanitize(Appointment $appointment): Appointment
    {
        foreach (['customer', 'customerVehicle', 'creator', 'updater', 'canceller', 'availabilityOverrider'] as $relation) {
            $appointment->unsetRelation($relation);
        }

        return $appointment->makeHidden(['customer_id', 'customer_vehicle_id', 'created_by', 'updated_by', 'cancelled_by',
            'availability_overridden_by', 'availability_override_reason', 'availability_overridden_at', 'contact_email', 'contact_phone']);
    }
}
