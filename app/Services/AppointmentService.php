<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\CrmNotification;
use App\Models\Customer;
use App\Models\CustomerVehicle;
use App\Models\Employee;
use App\Models\Service;
use App\Models\User;
use App\Support\Business\BusinessContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AppointmentService
{
    public function __construct(private readonly EmployeeAvailabilityService $availability, private readonly CrmNotificationService $notifications) {}

    public function paginate(array $filters, ?Employee $employee = null): LengthAwarePaginator
    {
        return Appointment::query()
            ->when($employee, fn ($q) => $q->where('responsible_employee_id', $employee->id))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['source'] ?? null, fn ($q, $v) => $q->where('source', $v))
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where('branch_id', $v))
            ->when($filters['responsible_employee_id'] ?? null, fn ($q, $v) => $q->where('responsible_employee_id', $v))
            ->when($filters['customer_id'] ?? null, fn ($q, $v) => $q->where('customer_id', $v))
            ->when($filters['customer_vehicle_id'] ?? null, fn ($q, $v) => $q->where('customer_vehicle_id', $v))
            ->when($filters['service_id'] ?? null, fn ($q, $v) => $q->where('service_id', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('ends_at', '>', $this->utc($v)))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('starts_at', '<', $this->utc($v)))
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where(fn ($match) => $match->where('title', 'like', "%{$v}%")
                ->orWhere('contact_name', 'like', "%{$v}%")->orWhere('contact_phone', 'like', "%{$v}%")
                ->orWhere('vehicle_description', 'like', "%{$v}%")->orWhere('service_name', 'like', "%{$v}%")))
            ->with($this->adminRelations())->orderBy('starts_at')->orderBy('id')
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function create(array $data, User $actor): Appointment
    {
        $appointment = DB::transaction(function () use ($data, $actor) {
            $employee = $this->lockEmployees([], $data['responsible_employee_id'] ?? null)->first();
            [$start, $end] = $this->range($data['starts_at'], $data['ends_at']);
            $status = $data['status'] ?? Appointment::STATUS_CONFIRMED;
            if ($employee && ! $employee->is_active) {
                $this->invalidEmployee();
            }
            $branch = $employee ? $this->activeBranchForEmployee($employee) : null;
            $audit = $status === Appointment::STATUS_CONFIRMED && $employee
                ? $this->availabilityAudit($employee, $start, $end, $data, null, $actor)
                : $this->emptyAudit();
            $relations = $this->resolveRelations($data, null);

            return Appointment::create([...$relations, ...$audit, 'responsible_employee_id' => $employee?->id, 'branch_id' => $branch?->id,
                'source' => Appointment::SOURCE_CRM, 'status' => $status, 'title' => $data['title'],
                'description' => $data['description'] ?? null, 'starts_at' => $start, 'ends_at' => $end,
                'created_by' => $actor->id, 'updated_by' => $actor->id]);
        });
        if ($appointment->status === Appointment::STATUS_CONFIRMED && $appointment->responsible_employee_id) {
            $this->notify($appointment, CrmNotification::TYPE_APPOINTMENT_ASSIGNED);
        }

        return $this->load($appointment);
    }

    public function update(Appointment $appointment, array $data, User $actor): Appointment
    {
        $oldEmployee = $appointment->responsible_employee_id;
        $appointment = DB::transaction(function () use ($appointment, $data, $actor) {
            $newEmployeeId = array_key_exists('responsible_employee_id', $data) ? $data['responsible_employee_id'] : $appointment->responsible_employee_id;
            $employees = $this->lockEmployees([$appointment->responsible_employee_id], $newEmployeeId);
            $appointment = Appointment::query()->lockForUpdate()->findOrFail($appointment->id);
            if ($appointment->isTerminal()) {
                $this->terminal();
            }
            $employee = $newEmployeeId ? $employees->get($newEmployeeId) : null;
            $audit = [];
            if (array_key_exists('responsible_employee_id', $data)) {
                if ($employee && ! $employee->is_active) {
                    $this->invalidEmployee();
                }
                $branchId = $this->branchIdForReassignment($appointment, $employee);
                $audit = in_array($appointment->status, [Appointment::STATUS_CONFIRMED, Appointment::STATUS_IN_PROGRESS], true) && $employee
                    ? $this->availabilityAudit($employee, $appointment->starts_at, $appointment->ends_at, $data, $appointment, $actor)
                    : $this->emptyAudit();
            } else {
                $branchId = $appointment->branch_id;
            }
            $relations = $this->resolveRelations($data, $appointment);
            $values = collect($data)->only(['title', 'description'])->all();
            $appointment->fill([...$values, ...$relations, ...$audit, 'responsible_employee_id' => $newEmployeeId,
                'branch_id' => $branchId, 'updated_by' => $actor->id])->save();

            return $appointment;
        });
        if ($oldEmployee !== $appointment->responsible_employee_id && in_array($appointment->status, [Appointment::STATUS_CONFIRMED, Appointment::STATUS_IN_PROGRESS], true)) {
            $this->notifyReassignment($appointment, $oldEmployee);
        }

        return $this->load($appointment);
    }

    public function reschedule(Appointment $appointment, array $data, User $actor): Appointment
    {
        $appointment = DB::transaction(function () use ($appointment, $data, $actor) {
            $employee = $this->lockEmployees([], $appointment->responsible_employee_id)->first();
            $appointment = Appointment::query()->lockForUpdate()->findOrFail($appointment->id);
            if (! in_array($appointment->status, [Appointment::STATUS_REQUESTED, Appointment::STATUS_CONFIRMED], true)) {
                throw ValidationException::withMessages(['status' => 'La cita no puede reprogramarse en su estado actual.']);
            }
            [$start, $end] = $this->range($data['starts_at'], $data['ends_at']);
            $audit = $appointment->status === Appointment::STATUS_CONFIRMED && $employee
                ? $this->availabilityAudit($employee, $start, $end, $data, $appointment, $actor)
                : $this->emptyAudit();
            $appointment->update([...$audit, 'starts_at' => $start, 'ends_at' => $end, 'updated_by' => $actor->id]);

            return $appointment;
        });
        if ($appointment->status === Appointment::STATUS_CONFIRMED && $appointment->responsible_employee_id) {
            $this->notify($appointment, CrmNotification::TYPE_APPOINTMENT_RESCHEDULED);
        }

        return $this->load($appointment);
    }

    public function changeStatus(Appointment $appointment, array $data, User $actor): Appointment
    {
        $from = $appointment->status;
        $appointment = DB::transaction(function () use ($appointment, $data, $actor) {
            $employee = $appointment->status === Appointment::STATUS_REQUESTED
                && $data['status'] === Appointment::STATUS_CONFIRMED
                ? $this->lockEmployees([], $appointment->responsible_employee_id)->first() : null;
            $appointment = Appointment::query()->lockForUpdate()->findOrFail($appointment->id);
            $to = $data['status'];
            $allowed = [Appointment::STATUS_REQUESTED => [Appointment::STATUS_CONFIRMED],
                Appointment::STATUS_CONFIRMED => [Appointment::STATUS_IN_PROGRESS, Appointment::STATUS_COMPLETED, Appointment::STATUS_NO_SHOW],
                Appointment::STATUS_IN_PROGRESS => [Appointment::STATUS_COMPLETED]];
            if (! in_array($to, $allowed[$appointment->status] ?? [], true)) {
                throw ValidationException::withMessages(['status' => 'La transición de estado no es válida.']);
            }
            $audit = [];
            if ($appointment->status === Appointment::STATUS_REQUESTED && $to === Appointment::STATUS_CONFIRMED && $appointment->responsible_employee_id) {
                if (! $employee) {
                    $this->invalidEmployee();
                }
                $branchId = $this->branchIdForReassignment($appointment, $employee);
                $audit = $this->availabilityAudit($employee, $appointment->starts_at, $appointment->ends_at, $data, $appointment, $actor);
            }
            $appointment->update([...$audit, 'branch_id' => $branchId ?? $appointment->branch_id, 'status' => $to, 'updated_by' => $actor->id]);

            return $appointment;
        });
        if ($from === Appointment::STATUS_REQUESTED && $appointment->status === Appointment::STATUS_CONFIRMED && $appointment->responsible_employee_id) {
            $this->notify($appointment, CrmNotification::TYPE_APPOINTMENT_ASSIGNED);
        }

        return $this->load($appointment);
    }

    public function cancel(Appointment $appointment, string $reason, User $actor): Appointment
    {
        $from = $appointment->status;
        $appointment = DB::transaction(function () use ($appointment, $reason, $actor) {
            $appointment = Appointment::query()->lockForUpdate()->findOrFail($appointment->id);
            if ($appointment->status === Appointment::STATUS_CANCELLED) {
                return $appointment;
            }
            if ($appointment->isTerminal()) {
                $this->terminal();
            }
            $appointment->update(['status' => Appointment::STATUS_CANCELLED, 'cancelled_by' => $actor->id,
                'cancelled_at' => now(), 'cancellation_reason' => $reason, 'updated_by' => $actor->id]);

            return $appointment;
        });
        if (in_array($from, [Appointment::STATUS_CONFIRMED, Appointment::STATUS_IN_PROGRESS], true) && $appointment->responsible_employee_id) {
            $this->notify($appointment, CrmNotification::TYPE_APPOINTMENT_CANCELLED);
        }

        return $this->load($appointment);
    }

    public function load(Appointment $appointment): Appointment
    {
        return $appointment->load($this->adminRelations());
    }

    private function resolveRelations(array $data, ?Appointment $appointment): array
    {
        $customerId = array_key_exists('customer_id', $data) ? $data['customer_id'] : $appointment?->customer_id;
        $customer = $customerId ? Customer::find($customerId) : null;
        $vehicleId = array_key_exists('customer_vehicle_id', $data) ? $data['customer_vehicle_id'] : $appointment?->customer_vehicle_id;
        $vehicle = $vehicleId ? CustomerVehicle::with(['vehicleBrand', 'vehicleModel', 'vehicleVersion'])->find($vehicleId) : null;
        if ($vehicle && ! $customer) {
            throw ValidationException::withMessages(['customer_vehicle_id' => 'Debes indicar el cliente propietario del vehículo.']);
        }
        if ($vehicle && (int) $vehicle->customer_id !== $customer?->id) {
            throw ValidationException::withMessages(['customer_vehicle_id' => 'El vehículo no pertenece al cliente indicado.']);
        }
        if ($appointment && ! array_key_exists('customer_id', $data) && $customer) {
            $contact = ['contact_name' => $appointment->contact_name, 'contact_phone' => $appointment->contact_phone, 'contact_email' => $appointment->contact_email];
        } elseif ($customer) {
            $contact = ['contact_name' => $customer->name, 'contact_phone' => $customer->phone, 'contact_email' => $customer->email];
        } else {
            $contact = ['contact_name' => $data['contact_name'] ?? $appointment?->contact_name,
                'contact_phone' => $data['contact_phone'] ?? $appointment?->contact_phone,
                'contact_email' => array_key_exists('contact_email', $data) ? $data['contact_email'] : $appointment?->contact_email];
            if (! trim((string) $contact['contact_name']) || ! trim((string) $contact['contact_phone'])) {
                throw ValidationException::withMessages(['contact_name' => 'El nombre y teléfono del contacto son obligatorios sin cliente registrado.']);
            }
        }
        if (! trim((string) $contact['contact_name']) || ! trim((string) $contact['contact_phone'])) {
            throw ValidationException::withMessages(['contact_phone' => 'El cliente o contacto debe tener un teléfono válido.']);
        }
        $serviceId = array_key_exists('service_id', $data) ? $data['service_id'] : $appointment?->service_id;
        $service = $serviceId ? Service::find($serviceId) : null;
        if (array_key_exists('service_id', $data) && $service && ! Service::query()->commerciallyAvailable()->whereKey($service->id)->exists()) {
            throw ValidationException::withMessages(['service_id' => 'El servicio debe estar activo y disponible.']);
        }

        return [...$contact, 'customer_id' => $customer?->id, 'customer_vehicle_id' => $vehicle?->id,
            'vehicle_description' => $vehicle
                ? ($appointment && ! array_key_exists('customer_vehicle_id', $data) ? $appointment->vehicle_description : $this->vehicleDescription($vehicle))
                : ($data['vehicle_description'] ?? $appointment?->vehicle_description),
            'service_id' => $service?->id, 'service_name' => $service ? ($appointment && ! array_key_exists('service_id', $data) ? $appointment->service_name : $service->name) : null];
    }

    private function vehicleDescription(CustomerVehicle $vehicle): ?string
    {
        $parts = array_filter([$vehicle->nickname, $vehicle->vehicleBrand?->name, $vehicle->vehicleModel?->name,
            $vehicle->vehicleVersion?->display_name, $vehicle->year, $vehicle->plate]);

        return $parts ? implode(' · ', $parts) : null;
    }

    private function availabilityAudit(Employee $employee, CarbonImmutable $start, CarbonImmutable $end, array $data, ?Appointment $appointment, User $actor): array
    {
        if (! $employee->is_active) {
            $this->invalidEmployee();
        }
        $this->assertBranchMatchesAppointment($employee, $appointment);
        $result = $this->availability->check($employee, $start, $end, ['appointment_id' => $appointment?->id]);
        if ($result->available) {
            return $this->emptyAudit();
        }
        $canOverride = $result->reasonCodes === [EmployeeAvailabilityService::REASON_OUTSIDE_SCHEDULE]
            && ($data['availability_override'] ?? false) && trim((string) ($data['availability_override_reason'] ?? '')) !== '';
        if (! $canOverride) {
            throw ValidationException::withMessages(['starts_at' => ['El empleado no está disponible.', ...$result->reasonCodes]]);
        }

        return ['availability_override' => true, 'availability_override_reason' => trim($data['availability_override_reason']),
            'availability_overridden_by' => $actor->id, 'availability_overridden_at' => now()];
    }

    private function range(mixed $startsAt, mixed $endsAt): array
    {
        $start = $this->utc($startsAt);
        $end = $this->utc($endsAt);
        if ($end->lessThanOrEqualTo($start)) {
            throw ValidationException::withMessages(['ends_at' => 'La fecha final debe ser posterior a la inicial.']);
        }
        if (! $start->setTimezone(BusinessContext::TIMEZONE)->isSameDay($end->setTimezone(BusinessContext::TIMEZONE))) {
            throw ValidationException::withMessages(['ends_at' => 'La cita debe permanecer dentro del mismo día en Bogotá.']);
        }

        return [$start, $end];
    }

    private function utc(mixed $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, BusinessContext::TIMEZONE)->utc();
    }

    private function emptyAudit(): array
    {
        return ['availability_override' => false, 'availability_override_reason' => null, 'availability_overridden_by' => null, 'availability_overridden_at' => null];
    }

    private function invalidEmployee(): never
    {
        throw ValidationException::withMessages(['responsible_employee_id' => 'El empleado responsable debe estar activo.']);
    }

    private function activeBranchForEmployee(Employee $employee): Branch
    {
        if ($employee->branch_id === null) {
            throw ValidationException::withMessages(['responsible_employee_id' => 'El empleado responsable debe tener una sede activa.']);
        }
        $branch = Branch::query()->lockForUpdate()->find($employee->branch_id);
        if (! $branch?->is_active) {
            throw ValidationException::withMessages(['responsible_employee_id' => 'El empleado responsable debe tener una sede activa.']);
        }

        return $branch;
    }

    private function branchIdForReassignment(Appointment $appointment, ?Employee $employee): ?int
    {
        if (! $employee) {
            return $appointment->branch_id;
        }
        $branch = $this->activeBranchForEmployee($employee);
        if ($appointment->branch_id === null) {
            return $branch->id;
        }
        if ((int) $appointment->branch_id !== (int) $branch->id) {
            throw ValidationException::withMessages(['responsible_employee_id' => 'El empleado seleccionado pertenece a una sede diferente a la cita.']);
        }

        return $appointment->branch_id;
    }

    private function assertBranchMatchesAppointment(Employee $employee, ?Appointment $appointment): void
    {
        if (! $appointment || $appointment->branch_id === null) {
            return;
        }
        $branch = $this->activeBranchForEmployee($employee);
        if ((int) $appointment->branch_id !== (int) $branch->id) {
            throw ValidationException::withMessages(['responsible_employee_id' => 'El empleado seleccionado pertenece a una sede diferente a la cita.']);
        }
    }

    private function terminal(): never
    {
        throw ValidationException::withMessages(['status' => 'Una cita terminal no puede modificarse.']);
    }

    private function lockEmployees(array $existingIds, ?int $newId)
    {
        $ids = array_values(array_unique(array_filter([...$existingIds, $newId])));

        return Employee::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
    }

    private function notify(Appointment $appointment, string $type, ?User $specific = null): void
    {
        $user = $specific ?? $appointment->responsibleEmployee()->with('user')->first()?->user;
        if (! $user?->is_active) {
            return;
        }
        try {
            $this->notifications->createFor($user, $this->notificationPayload($appointment, $type));
        } catch (\Throwable $exception) {
            Log::warning('Appointment notification failed.', ['appointment_id' => $appointment->id, 'type' => $type]);
        }
    }

    private function notifyReassignment(Appointment $appointment, ?int $oldEmployeeId): void
    {
        $old = $oldEmployeeId ? Employee::with('user')->find($oldEmployeeId)?->user : null;
        if ($old) {
            $this->notify($appointment, CrmNotification::TYPE_APPOINTMENT_REASSIGNED, $old);
        }
        $this->notify($appointment, CrmNotification::TYPE_APPOINTMENT_ASSIGNED);
    }

    private function notificationPayload(Appointment $appointment, string $type): array
    {
        $labels = [CrmNotification::TYPE_APPOINTMENT_ASSIGNED => ['Cita asignada', 'Tienes una cita asignada.'],
            CrmNotification::TYPE_APPOINTMENT_RESCHEDULED => ['Cita reprogramada', 'Cambió el horario de una cita.'],
            CrmNotification::TYPE_APPOINTMENT_REASSIGNED => ['Cita reasignada', 'Ya no eres responsable de esta cita.'],
            CrmNotification::TYPE_APPOINTMENT_CANCELLED => ['Cita cancelada', 'Una cita asignada fue cancelada.']];

        return ['type' => $type, 'severity' => $type === CrmNotification::TYPE_APPOINTMENT_CANCELLED ? CrmNotification::SEVERITY_WARNING : CrmNotification::SEVERITY_INFO,
            'title' => $labels[$type][0], 'message' => $labels[$type][1], 'reference_type' => 'appointment', 'reference_id' => $appointment->id,
            'dedupe_key' => $this->notificationKey($appointment, $type),
            'data' => ['appointment_id' => $appointment->id, 'title' => $appointment->title, 'starts_at' => $appointment->starts_at?->toISOString(), 'ends_at' => $appointment->ends_at?->toISOString()]];
    }

    private function notificationKey(Appointment $appointment, string $type): string
    {
        $context = match ($type) {
            CrmNotification::TYPE_APPOINTMENT_RESCHEDULED => $appointment->starts_at?->toISOString().':'.$appointment->ends_at?->toISOString(),
            CrmNotification::TYPE_APPOINTMENT_ASSIGNED, CrmNotification::TYPE_APPOINTMENT_REASSIGNED => (string) $appointment->responsible_employee_id,
            default => (string) $appointment->updated_at?->getTimestamp(),
        };

        return "{$type}:{$appointment->id}:{$context}";
    }

    private function adminRelations(): array
    {
        return ['customer:id,name', 'customerVehicle:id,customer_id,nickname,year,plate', 'service:id,name,is_active,estimated_duration_minutes',
            'branch:id,code,name', 'responsibleEmployee:id,user_id,branch_id,name,job_title,specialty,is_active', 'creator:id,name', 'updater:id,name', 'canceller:id,name', 'availabilityOverrider:id,name'];
    }
}
