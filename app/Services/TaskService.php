<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\CrmNotification;
use App\Models\Employee;
use App\Models\Task;
use App\Models\User;
use App\Support\Business\BusinessContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class TaskService
{
    public function __construct(
        private readonly EmployeeAvailabilityService $availability,
        private readonly CrmNotificationService $notifications,
    ) {}

    public function paginate(array $filters, ?Employee $employee = null): LengthAwarePaginator
    {
        return $this->filteredQuery($filters, $employee)
            ->with($this->relations())
            ->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END')->orderBy('due_at')->orderByDesc('id')
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function create(array $data, User $actor): Task
    {
        $task = DB::transaction(function () use ($data, $actor) {
            $employee = Employee::query()->lockForUpdate()->findOrFail($data['assigned_employee_id']);
            $branch = $this->activeBranchForEmployee($employee);
            [$values, $audit] = $this->prepareValues($data, $employee, null, $actor);

            return Task::create([...$values, ...$audit, 'branch_id' => $branch->id, 'status' => Task::STATUS_PENDING,
                'priority' => $values['priority'] ?? Task::PRIORITY_NORMAL,
                'created_by' => $actor->id, 'updated_by' => $actor->id]);
        });
        $this->notify($task, CrmNotification::TYPE_TASK_ASSIGNED);

        return $task->load($this->relations());
    }

    public function update(Task $task, array $data, User $actor): Task
    {
        $beforeEmployee = $task->assigned_employee_id;
        $beforeSchedule = [$task->scheduled_starts_at?->toISOString(), $task->scheduled_ends_at?->toISOString()];
        $task = DB::transaction(function () use ($task, $data, $actor) {
            $task = Task::query()->lockForUpdate()->findOrFail($task->id);
            if ($task->isTerminal()) {
                throw ValidationException::withMessages(['status' => 'Una tarea terminada no puede editarse.']);
            }
            $employeeId = $data['assigned_employee_id'] ?? $task->assigned_employee_id;
            $employees = Employee::query()->whereKey(array_unique([$task->assigned_employee_id, $employeeId]))
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $employee = $employees->get($employeeId) ?? Employee::query()->findOrFail($employeeId);
            $merged = array_merge($task->only(['assigned_employee_id', 'title', 'description', 'priority', 'due_at', 'scheduled_starts_at', 'scheduled_ends_at']), $data);
            $availabilityChanged = array_key_exists('assigned_employee_id', $data)
                || array_key_exists('scheduled_starts_at', $data)
                || array_key_exists('scheduled_ends_at', $data);
            $branchId = $task->branch_id;
            if (array_key_exists('assigned_employee_id', $data)) {
                $branchId = $this->branchIdForReassignment($task, $employee);
            }
            if ($availabilityChanged) {
                [$values, $audit] = $this->prepareValues($merged, $employee, $task, $actor);
            } else {
                $values = collect($data)->only(['title', 'description', 'priority'])->all();
                if (array_key_exists('due_at', $data)) {
                    $values['due_at'] = $data['due_at'] === null ? null : CarbonImmutable::parse($data['due_at'], BusinessContext::TIMEZONE)->utc();
                }
                $audit = [];
            }
            $task->fill([...$values, ...$audit, 'branch_id' => $branchId, 'updated_by' => $actor->id])->save();

            return $task;
        });
        if ($beforeEmployee !== $task->assigned_employee_id) {
            $this->notifyReassignment($task, $beforeEmployee);
        } elseif ($beforeSchedule !== [$task->scheduled_starts_at?->toISOString(), $task->scheduled_ends_at?->toISOString()]) {
            $this->notify($task, CrmNotification::TYPE_TASK_RESCHEDULED);
        }

        return $task->load($this->relations());
    }

    public function start(Task $task, User $actor): Task
    {
        return $this->transition($task, $actor, Task::STATUS_IN_PROGRESS);
    }

    public function complete(Task $task, User $actor): Task
    {
        return $this->transition($task, $actor, Task::STATUS_COMPLETED);
    }

    public function cancel(Task $task, string $reason, User $actor): Task
    {
        $task = DB::transaction(function () use ($task, $reason, $actor) {
            $task = Task::query()->lockForUpdate()->findOrFail($task->id);
            if ($task->status === Task::STATUS_CANCELLED) {
                return $task;
            }
            if ($task->status === Task::STATUS_COMPLETED) {
                throw ValidationException::withMessages(['status' => 'Una tarea completada no puede cancelarse.']);
            }
            $task->update(['status' => Task::STATUS_CANCELLED, 'cancelled_by' => $actor->id,
                'cancelled_at' => now(), 'cancellation_reason' => $reason, 'updated_by' => $actor->id]);

            return $task;
        });
        $this->notify($task, CrmNotification::TYPE_TASK_CANCELLED);

        return $task->load($this->relations());
    }

    private function transition(Task $task, User $actor, string $status): Task
    {
        $task = DB::transaction(function () use ($task, $actor, $status) {
            $task = Task::query()->lockForUpdate()->findOrFail($task->id);
            if ($task->status === $status) {
                return $task;
            }
            $allowed = $status === Task::STATUS_IN_PROGRESS
                ? [Task::STATUS_PENDING]
                : [Task::STATUS_PENDING, Task::STATUS_IN_PROGRESS];
            if (! in_array($task->status, $allowed, true)) {
                throw ValidationException::withMessages(['status' => 'La transición de estado no es válida.']);
            }
            $values = ['status' => $status, 'updated_by' => $actor->id];
            if ($status === Task::STATUS_IN_PROGRESS) {
                $values['started_at'] = now();
            } else {
                $values['completed_at'] = now();
            }
            $task->update($values);

            return $task;
        });

        return $task->load($this->relations());
    }

    private function prepareValues(array $data, Employee $employee, ?Task $task, User $actor): array
    {
        if (! $employee->is_active) {
            throw ValidationException::withMessages(['assigned_employee_id' => 'El empleado asignado debe estar activo.']);
        }
        $this->assertBranchMatchesTask($employee, $task);
        $values = collect($data)->only(['assigned_employee_id', 'title', 'description', 'priority'])->all();
        $values['assigned_employee_id'] = $employee->id;
        foreach (['due_at', 'scheduled_starts_at', 'scheduled_ends_at'] as $field) {
            $values[$field] = isset($data[$field]) ? CarbonImmutable::parse($data[$field], BusinessContext::TIMEZONE)->utc() : null;
        }
        $start = $values['scheduled_starts_at'];
        $end = $values['scheduled_ends_at'];
        if (($start === null) !== ($end === null)) {
            throw ValidationException::withMessages(['scheduled_ends_at' => 'El inicio y fin programados deben enviarse juntos.']);
        }
        $audit = ['availability_override' => false, 'availability_override_reason' => null,
            'availability_overridden_by' => null, 'availability_overridden_at' => null];
        if ($start && $end) {
            if (! $start->setTimezone(BusinessContext::TIMEZONE)->isSameDay($end->setTimezone(BusinessContext::TIMEZONE))) {
                throw ValidationException::withMessages(['scheduled_ends_at' => 'La programación debe permanecer dentro del mismo día en Bogotá.']);
            }
            $result = $this->availability->check($employee, $start, $end, ['task_id' => $task?->id]);
            if (! $result->available) {
                $canOverride = $result->reasonCodes === [EmployeeAvailabilityService::REASON_OUTSIDE_SCHEDULE]
                    && ($data['availability_override'] ?? false)
                    && trim((string) ($data['availability_override_reason'] ?? '')) !== '';
                if (! $canOverride) {
                    throw ValidationException::withMessages(['scheduled_starts_at' => ['El empleado no está disponible.', ...$result->reasonCodes]]);
                }
                $audit = ['availability_override' => true,
                    'availability_override_reason' => trim($data['availability_override_reason']),
                    'availability_overridden_by' => $actor->id, 'availability_overridden_at' => now()];
            }
        }

        return [$values, $audit];
    }

    private function filteredQuery(array $filters, ?Employee $employee): Builder
    {
        return Task::query()->when($employee, fn ($q) => $q->where('assigned_employee_id', $employee->id))
            ->when($filters['employee_id'] ?? null, fn ($q, $v) => $q->where('assigned_employee_id', $v))
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where('branch_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['priority'] ?? null, fn ($q, $v) => $q->where('priority', $v))
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where(fn ($qq) => $qq->where('title', 'like', "%{$v}%")->orWhere('description', 'like', "%{$v}%")))
            ->when($filters['due_from'] ?? null, fn ($q, $v) => $q->where('due_at', '>=', CarbonImmutable::parse($v, BusinessContext::TIMEZONE)->utc()))
            ->when($filters['due_to'] ?? null, fn ($q, $v) => $q->where('due_at', '<=', CarbonImmutable::parse($v, BusinessContext::TIMEZONE)->utc()))
            ->when($filters['scheduled_from'] ?? null, fn ($q, $v) => $q->where('scheduled_ends_at', '>', CarbonImmutable::parse($v, BusinessContext::TIMEZONE)->utc()))
            ->when($filters['scheduled_to'] ?? null, fn ($q, $v) => $q->where('scheduled_starts_at', '<', CarbonImmutable::parse($v, BusinessContext::TIMEZONE)->utc()));
    }

    private function notify(Task $task, string $type): void
    {
        $employee = $task->employee()->with('user')->first();
        if (! $employee?->user?->is_active) {
            return;
        }
        try {
            $this->notifications->createFor($employee->user, $this->notificationPayload($task, $type));
        } catch (\Throwable $exception) {
            Log::warning('Task notification failed.', ['task_id' => $task->id, 'type' => $type, 'exception' => $exception->getMessage()]);
        }
    }

    private function notifyReassignment(Task $task, int $oldEmployeeId): void
    {
        $old = Employee::with('user')->find($oldEmployeeId);
        foreach ([$old?->user, $task->employee()->with('user')->first()?->user] as $user) {
            if (! $user?->is_active) {
                continue;
            }
            try {
                $this->notifications->createFor($user, $this->notificationPayload($task, CrmNotification::TYPE_TASK_REASSIGNED));
            } catch (\Throwable $exception) {
                Log::warning('Task reassignment notification failed.', ['task_id' => $task->id]);
            }
        }
    }

    private function notificationPayload(Task $task, string $type): array
    {
        $labels = [CrmNotification::TYPE_TASK_ASSIGNED => ['Tarea asignada', 'Tienes una nueva tarea.'],
            CrmNotification::TYPE_TASK_RESCHEDULED => ['Tarea reprogramada', 'Cambió la programación de una tarea.'],
            CrmNotification::TYPE_TASK_REASSIGNED => ['Tarea reasignada', 'Cambió la asignación de una tarea.'],
            CrmNotification::TYPE_TASK_CANCELLED => ['Tarea cancelada', 'Una tarea asignada fue cancelada.']];

        return ['type' => $type, 'severity' => $type === CrmNotification::TYPE_TASK_CANCELLED ? CrmNotification::SEVERITY_WARNING : CrmNotification::SEVERITY_INFO,
            'title' => $labels[$type][0], 'message' => $labels[$type][1], 'reference_type' => 'task', 'reference_id' => $task->id,
            'dedupe_key' => "{$type}:{$task->id}:{$task->updated_at?->getTimestamp()}", 'data' => ['task_id' => $task->id, 'title' => $task->title,
                'due_at' => $task->due_at?->toISOString(), 'scheduled_starts_at' => $task->scheduled_starts_at?->toISOString(),
                'scheduled_ends_at' => $task->scheduled_ends_at?->toISOString()]];
    }

    private function relations(): array
    {
        return ['employee:id,name,job_title,specialty,is_active,user_id', 'branch:id,code,name', 'creator:id,name', 'updater:id,name', 'canceller:id,name', 'availabilityOverrider:id,name'];
    }

    private function activeBranchForEmployee(Employee $employee): Branch
    {
        if ($employee->branch_id === null) {
            throw ValidationException::withMessages(['assigned_employee_id' => 'El empleado asignado debe tener una sede activa.']);
        }
        $branch = Branch::query()->lockForUpdate()->find($employee->branch_id);
        if (! $branch?->is_active) {
            throw ValidationException::withMessages(['assigned_employee_id' => 'El empleado asignado debe tener una sede activa.']);
        }

        return $branch;
    }

    private function branchIdForReassignment(Task $task, Employee $employee): int
    {
        $branch = $this->activeBranchForEmployee($employee);
        if ($task->branch_id === null) {
            return $branch->id;
        }
        if ((int) $task->branch_id !== (int) $branch->id) {
            throw ValidationException::withMessages(['assigned_employee_id' => 'El empleado seleccionado pertenece a una sede diferente a la tarea.']);
        }

        return $task->branch_id;
    }

    private function assertBranchMatchesTask(Employee $employee, ?Task $task): void
    {
        if (! $task || $task->branch_id === null) {
            return;
        }
        $branch = $this->activeBranchForEmployee($employee);
        if ((int) $task->branch_id !== (int) $branch->id) {
            throw ValidationException::withMessages(['assigned_employee_id' => 'El empleado seleccionado pertenece a una sede diferente a la tarea.']);
        }
    }
}
