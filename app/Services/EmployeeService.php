<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeBranchAssignment;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmployeeService
{
    public function create(array $data, User $actor): Employee
    {
        return DB::transaction(function () use ($data, $actor) {
            $branchId = $this->normalizeBranchId($data['branch_id'] ?? null);
            if ($branchId !== null) {
                $this->requireActiveBranch($branchId);
            }

            $employee = Employee::create([
                ...$data,
                'branch_id' => $branchId,
                'is_active' => $data['is_active'] ?? true,
            ]);

            if ($branchId !== null) {
                $this->recordBranchChange($employee, null, $branchId, $actor);
            }

            return $employee;
        });
    }

    public function update(Employee $employee, array $data, User $actor): Employee
    {
        return DB::transaction(function () use ($employee, $data, $actor) {
            $locked = Employee::query()->lockForUpdate()->findOrFail($employee->id);
            $branchWasProvided = array_key_exists('branch_id', $data);
            $fromBranchId = $this->normalizeBranchId($locked->branch_id);
            $toBranchId = $branchWasProvided
                ? $this->normalizeBranchId($data['branch_id'])
                : $fromBranchId;
            $willBeActive = array_key_exists('is_active', $data)
                ? (bool) $data['is_active']
                : $locked->is_active;
            $isReactivation = ! $locked->is_active && $willBeActive;

            if ($toBranchId !== null
                && (($branchWasProvided && $fromBranchId !== $toBranchId) || $isReactivation)) {
                $this->requireActiveBranch($toBranchId);
            }
            if ($branchWasProvided && $fromBranchId !== null && $fromBranchId !== $toBranchId) {
                $this->preventSilentBranchMoveWithFutureAppointments($locked, $fromBranchId);
                $this->preventSilentBranchMoveWithFutureTasks($locked, $fromBranchId);
            }

            $locked->update($data);

            if ($branchWasProvided && $fromBranchId !== $toBranchId) {
                $this->recordBranchChange($locked, $fromBranchId, $toBranchId, $actor);
            }

            return $locked->fresh();
        });
    }

    private function requireActiveBranch(int $branchId): void
    {
        $branch = Branch::query()->lockForUpdate()->find($branchId);
        if (! $branch?->is_active) {
            throw ValidationException::withMessages([
                'branch_id' => 'La sede seleccionada no está activa.',
            ]);
        }
    }

    private function recordBranchChange(
        Employee $employee,
        ?int $fromBranchId,
        ?int $toBranchId,
        User $actor,
    ): void {
        EmployeeBranchAssignment::create([
            'employee_id' => $employee->id,
            'from_branch_id' => $fromBranchId,
            'to_branch_id' => $toBranchId,
            'changed_by' => $actor->id,
            'reason' => null,
            'changed_at' => now(),
        ]);
    }

    private function preventSilentBranchMoveWithFutureAppointments(Employee $employee, int $fromBranchId): void
    {
        $hasFutureActiveAppointments = Appointment::query()
            ->where('responsible_employee_id', $employee->id)
            ->where('branch_id', $fromBranchId)
            ->whereIn('status', [Appointment::STATUS_REQUESTED, Appointment::STATUS_CONFIRMED, Appointment::STATUS_IN_PROGRESS])
            ->where('starts_at', '>=', now())
            ->exists();

        if ($hasFutureActiveAppointments) {
            throw ValidationException::withMessages([
                'branch_id' => 'El empleado tiene citas futuras activas en su sede actual. Reasigna o cancela esas citas antes de cambiarlo de sede.',
            ]);
        }
    }

    private function preventSilentBranchMoveWithFutureTasks(Employee $employee, int $fromBranchId): void
    {
        $hasFutureActiveTasks = Task::query()
            ->where('assigned_employee_id', $employee->id)
            ->where('branch_id', $fromBranchId)
            ->whereIn('status', [Task::STATUS_PENDING, Task::STATUS_IN_PROGRESS])
            ->whereNotNull('scheduled_starts_at')
            ->whereNotNull('scheduled_ends_at')
            ->where('scheduled_ends_at', '>', now())
            ->exists();

        if ($hasFutureActiveTasks) {
            throw ValidationException::withMessages([
                'branch_id' => 'El empleado tiene tareas futuras activas en su sede actual. Reasigna o completa esas tareas antes de cambiarlo de sede.',
            ]);
        }
    }

    private function normalizeBranchId(mixed $branchId): ?int
    {
        return $branchId === null ? null : (int) $branchId;
    }
}
