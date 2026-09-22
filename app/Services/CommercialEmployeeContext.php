<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class CommercialEmployeeContext
{
    /**
     * Resolve an explicit Admin assignment using the same employee-before-branch
     * lock order as personal commercial mutations.
     *
     * @return array{0: Branch, 1: Employee|null}
     */
    public function adminAssignment(int $branchId, ?int $employeeId): array
    {
        $branch = $this->activeBranch($branchId);
        $employee = $this->optionalSeller($employeeId, $branch, true);
        $branch = $this->activeBranch($branchId, true);
        $employee?->setRelation('branch', $branch);

        return [$branch, $employee];
    }

    /**
     * @return array{employee: Employee, branch: Branch}
     */
    public function resolve(User $user, bool $lockForUpdate = false): array
    {
        $currentUser = User::query()
            ->whereKey($user->id)
            ->when($lockForUpdate, fn (Builder $query) => $query->lockForUpdate())
            ->first();

        if (! $currentUser?->is_active) {
            throw ValidationException::withMessages([
                'user' => 'Tu usuario debe estar activo para realizar esta operación.',
            ]);
        }

        $employee = Employee::query()
            ->where('user_id', $currentUser->id)
            ->when($lockForUpdate, fn (Builder $query) => $query->lockForUpdate())
            ->first();

        if (! $employee) {
            throw ValidationException::withMessages([
                'employee' => 'Tu usuario no está vinculado a un empleado.',
            ]);
        }

        if (! $employee->is_active) {
            throw ValidationException::withMessages([
                'employee' => 'El empleado vinculado debe estar activo.',
            ]);
        }

        if ($employee->branch_id === null) {
            throw ValidationException::withMessages([
                'branch_id' => 'Tu empleado no tiene una sede asignada.',
            ]);
        }

        $branch = $this->activeBranch((int) $employee->branch_id, $lockForUpdate);
        $employee->setRelation('branch', $branch);

        return [
            'employee' => $employee,
            'branch' => $branch,
        ];
    }

    /**
     * @return array{employee: Employee, branch: Branch}
     */
    public function resolveForBranch(User $user, int $branchId, bool $lockForUpdate = false): array
    {
        $context = $this->resolve($user, $lockForUpdate);
        $this->assertSnapshot($context['employee'], $branchId);

        return $context;
    }

    public function activeBranch(int $branchId, bool $lockForUpdate = false): Branch
    {
        $branch = Branch::query()
            ->whereKey($branchId)
            ->when($lockForUpdate, fn (Builder $query) => $query->lockForUpdate())
            ->first();

        if (! $branch?->is_active) {
            throw ValidationException::withMessages([
                'branch_id' => 'La sede seleccionada no está activa.',
            ]);
        }

        return $branch;
    }

    public function optionalSeller(
        ?int $employeeId,
        Branch $branch,
        bool $lockForUpdate = false,
    ): ?Employee {
        if ($employeeId === null) {
            return null;
        }

        $employee = Employee::query()
            ->whereKey($employeeId)
            ->when($lockForUpdate, fn (Builder $query) => $query->lockForUpdate())
            ->first();

        if (! $employee?->is_active) {
            throw ValidationException::withMessages([
                'sales_employee_id' => 'El vendedor seleccionado debe estar activo.',
            ]);
        }

        if ((int) $employee->branch_id !== (int) $branch->id) {
            throw ValidationException::withMessages([
                'sales_employee_id' => 'El vendedor seleccionado debe pertenecer a la sede de la operación.',
            ]);
        }

        $employee->setRelation('branch', $branch);

        return $employee;
    }

    public function assertSnapshot(Employee $employee, int $branchId): void
    {
        if ((int) $employee->branch_id !== $branchId) {
            throw ValidationException::withMessages([
                'branch_id' => 'La operación pertenece a una sede diferente de tu sede actual.',
            ]);
        }
    }
}
