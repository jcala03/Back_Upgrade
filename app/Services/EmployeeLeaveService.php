<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\User;
use App\Support\Business\BusinessContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmployeeLeaveService
{
    public function create(array $data, User $user): EmployeeLeave
    {
        return DB::transaction(function () use ($data, $user) {
            $this->lockEmployee((int) $data['employee_id']);
            $values = $this->normalizeRange($data);
            $this->validateRangeAndOverlap($values);
            $leave = EmployeeLeave::create([
                ...$values,
                'status' => EmployeeLeave::STATUS_APPROVED,
                'approved_by' => $user->id,
                'approved_at' => now(),
            ]);

            return $leave->load(['employee:id,name,is_active', 'approver:id,name']);
        });
    }

    public function update(EmployeeLeave $leave, array $data): EmployeeLeave
    {
        return DB::transaction(function () use ($leave, $data) {
            $locked = EmployeeLeave::query()->lockForUpdate()->findOrFail($leave->id);
            if ($locked->status !== EmployeeLeave::STATUS_APPROVED) {
                throw ValidationException::withMessages(['status' => 'Una ausencia cancelada no puede editarse.']);
            }
            $values = [
                ...$locked->only(['employee_id', 'type', 'reason', 'notes']),
                'starts_at' => $locked->starts_at->toIso8601String(),
                'ends_at' => $locked->ends_at->toIso8601String(),
                ...$data,
            ];
            $this->lockEmployees([(int) $locked->employee_id, (int) $values['employee_id']]);
            $values = $this->normalizeRange($values);
            $this->validateRangeAndOverlap($values, $locked->id);
            $locked->update($values);

            return $locked->fresh()->load(['employee:id,name,is_active', 'approver:id,name', 'canceller:id,name']);
        });
    }

    public function cancel(EmployeeLeave $leave, User $user): EmployeeLeave
    {
        return DB::transaction(function () use ($leave, $user) {
            $locked = EmployeeLeave::query()->lockForUpdate()->findOrFail($leave->id);
            $this->lockEmployee((int) $locked->employee_id);
            if ($locked->status === EmployeeLeave::STATUS_CANCELLED) {
                return $locked->load(['employee:id,name,is_active', 'approver:id,name', 'canceller:id,name']);
            }
            $locked->update([
                'status' => EmployeeLeave::STATUS_CANCELLED,
                'cancelled_by' => $user->id,
                'cancelled_at' => now(),
            ]);

            return $locked->fresh()->load(['employee:id,name,is_active', 'approver:id,name', 'canceller:id,name']);
        });
    }

    private function normalizeRange(array $data): array
    {
        $data['starts_at'] = CarbonImmutable::parse($data['starts_at'], BusinessContext::TIMEZONE)->utc()->format('Y-m-d H:i:s');
        $data['ends_at'] = CarbonImmutable::parse($data['ends_at'], BusinessContext::TIMEZONE)->utc()->format('Y-m-d H:i:s');

        return $data;
    }

    private function validateRangeAndOverlap(array $data, ?int $ignoreId = null): void
    {
        if ($data['ends_at'] <= $data['starts_at']) {
            throw ValidationException::withMessages(['ends_at' => 'La fecha final debe ser posterior a la inicial.']);
        }
        if (EmployeeLeave::query()->where('employee_id', $data['employee_id'])->approved()
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->overlapping($data['starts_at'], $data['ends_at'])->exists()) {
            throw ValidationException::withMessages(['starts_at' => 'La ausencia se solapa con otra ausencia aprobada del empleado.']);
        }
    }

    private function lockEmployee(int $id): void
    {
        $this->lockEmployees([$id]);
    }

    private function lockEmployees(array $ids): void
    {
        $ids = array_values(array_unique($ids));
        sort($ids);
        if (Employee::query()->whereKey($ids)->lockForUpdate()->get(['id'])->count() !== count($ids)) {
            throw ValidationException::withMessages(['employee_id' => 'El empleado seleccionado no existe.']);
        }
    }
}
