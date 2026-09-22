<?php

namespace App\Services;

use App\Models\CrmNotification;
use App\Models\Employee;
use App\Models\Goal;
use App\Models\User;
use App\Support\Business\BusinessContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class GoalService
{
    public function __construct(private readonly CrmNotificationService $notifications) {}

    public function paginate(array $filters, ?Employee $employee = null): LengthAwarePaginator
    {
        $today = BusinessContext::today()->toDateString();

        return Goal::query()->when($employee, fn ($q) => $q->where('employee_id', $employee->id))
            ->when($filters['employee_id'] ?? null, fn ($q, $v) => $q->where('employee_id', $v))
            ->when($filters['source'] ?? null, fn ($q, $v) => $q->where('source', $v))
            ->when($filters['status'] ?? null, function ($q, $status) use ($today) {
                if ($status === Goal::EFFECTIVE_EXPIRED) {
                    $q->where('status', Goal::STATUS_ACTIVE)->whereNotNull('due_on')->whereDate('due_on', '<', $today);
                } elseif ($status === Goal::STATUS_ACTIVE) {
                    $q->where('status', Goal::STATUS_ACTIVE)->where(fn ($range) => $range->whereNull('due_on')->orWhereDate('due_on', '>=', $today));
                } else {
                    $q->where('status', $status);
                }
            })->when($filters['due_from'] ?? null, fn ($q, $v) => $q->whereDate('due_on', '>=', $v))
            ->when($filters['due_to'] ?? null, fn ($q, $v) => $q->whereDate('due_on', '<=', $v))
            ->when($filters['starts_from'] ?? null, fn ($q, $v) => $q->whereDate('starts_on', '>=', $v))
            ->when($filters['starts_to'] ?? null, fn ($q, $v) => $q->whereDate('starts_on', '<=', $v))
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where(fn ($match) => $match->where('title', 'like', "%{$v}%")->orWhere('description', 'like', "%{$v}%")))
            ->with($this->relations())->orderByRaw('CASE WHEN due_on IS NULL THEN 1 ELSE 0 END')->orderBy('due_on')->orderByDesc('id')
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function createAssigned(array $data, User $actor): Goal
    {
        $employee = Employee::findOrFail($data['employee_id']);
        $this->requireActiveEmployee($employee);
        $goal = DB::transaction(fn () => Goal::create([...$this->definition($data), 'employee_id' => $employee->id,
            'source' => Goal::SOURCE_ASSIGNED, 'metric_type' => Goal::METRIC_MANUAL, 'current_value' => 0,
            'status' => Goal::STATUS_ACTIVE, 'created_by' => $actor->id, 'updated_by' => $actor->id]));
        $this->notify($goal, CrmNotification::TYPE_GOAL_ASSIGNED);

        return $this->load($goal);
    }

    public function createPersonal(array $data, User $actor): Goal
    {
        $employee = $actor->employee;
        if (! $employee) {
            throw ValidationException::withMessages(['employee' => 'Tu usuario no está vinculado a un empleado.']);
        }
        $this->requireActiveEmployee($employee);

        return $this->load(DB::transaction(fn () => Goal::create([...$this->definition($data), 'employee_id' => $employee->id,
            'source' => Goal::SOURCE_PERSONAL, 'metric_type' => Goal::METRIC_MANUAL, 'current_value' => 0,
            'status' => Goal::STATUS_ACTIVE, 'created_by' => $actor->id, 'updated_by' => $actor->id])));
    }

    public function updateAdmin(Goal $goal, array $data, User $actor): Goal
    {
        $oldEmployee = $goal->employee_id;
        $goal = DB::transaction(function () use ($goal, $data, $actor) {
            $goal = Goal::query()->lockForUpdate()->findOrFail($goal->id);
            $this->requireActiveGoal($goal);
            if (array_key_exists('employee_id', $data) && (int) $data['employee_id'] !== $goal->employee_id) {
                if ($goal->source !== Goal::SOURCE_ASSIGNED) {
                    throw ValidationException::withMessages(['employee_id' => 'Una meta personal no puede reasignarse.']);
                }
                $this->requireActiveEmployee(Employee::findOrFail($data['employee_id']));
            }
            $merged = array_merge($goal->only(['title', 'description', 'target_value', 'unit', 'starts_on', 'due_on']), $data);
            $goal->fill([...$this->definition($merged), 'employee_id' => $data['employee_id'] ?? $goal->employee_id, 'updated_by' => $actor->id])->save();

            return $goal;
        });
        if ($goal->source === Goal::SOURCE_ASSIGNED) {
            if ($oldEmployee !== $goal->employee_id) {
                $this->notifyReassignment($goal, $oldEmployee);
            } elseif (array_intersect(array_keys($data), ['title', 'target_value', 'unit', 'starts_on', 'due_on', 'description'])) {
                $this->notify($goal, CrmNotification::TYPE_GOAL_UPDATED);
            }
        }

        return $this->load($goal);
    }

    public function updatePersonal(Goal $goal, array $data, User $actor): Goal
    {
        $this->requireMutableUser($goal, $actor);
        if ($goal->source !== Goal::SOURCE_PERSONAL) {
            throw ValidationException::withMessages(['source' => 'No puedes editar la definición de una meta asignada.']);
        }
        $merged = array_merge($goal->only(['title', 'description', 'target_value', 'unit', 'starts_on', 'due_on']), $data);
        $goal->update([...$this->definition($merged), 'updated_by' => $actor->id]);

        return $this->load($goal);
    }

    public function updateProgress(Goal $goal, mixed $value, User $actor, bool $personal): Goal
    {
        if ($personal) {
            $this->requireMutableUser($goal, $actor);
        } else {
            $this->requireActiveGoal($goal);
        }
        $goal->update(['current_value' => $value, 'updated_by' => $actor->id]);

        return $this->load($goal);
    }

    public function complete(Goal $goal, User $actor, bool $personal): Goal
    {
        if ($personal) {
            $this->requireMutableUser($goal, $actor);
        }

        return $this->load(DB::transaction(function () use ($goal, $actor) {
            $goal = Goal::query()->lockForUpdate()->findOrFail($goal->id);
            $this->requireActiveGoal($goal);
            $goal->update(['status' => Goal::STATUS_COMPLETED, 'completed_at' => now(), 'completed_by' => $actor->id, 'updated_by' => $actor->id]);

            return $goal;
        }));
    }

    public function cancelPersonal(Goal $goal, ?string $reason, User $actor): Goal
    {
        $this->requireMutableUser($goal, $actor);
        if ($goal->source !== Goal::SOURCE_PERSONAL) {
            throw ValidationException::withMessages(['source' => 'No puedes cancelar una meta asignada.']);
        }

        return $this->cancelGoal($goal, $reason, $actor, false);
    }

    public function cancelAssigned(Goal $goal, string $reason, User $actor): Goal
    {
        if ($goal->source !== Goal::SOURCE_ASSIGNED) {
            throw ValidationException::withMessages(['source' => 'La cancelación administrativa aplica a metas asignadas.']);
        }

        return $this->cancelGoal($goal, $reason, $actor, true);
    }

    public function load(Goal $goal): Goal
    {
        return $goal->load($this->relations());
    }

    private function cancelGoal(Goal $goal, ?string $reason, User $actor, bool $notify): Goal
    {
        $goal = DB::transaction(function () use ($goal, $reason, $actor) {
            $goal = Goal::query()->lockForUpdate()->findOrFail($goal->id);
            $this->requireActiveGoal($goal);
            $goal->update(['status' => Goal::STATUS_CANCELLED, 'cancelled_at' => now(), 'cancelled_by' => $actor->id,
                'cancellation_reason' => $reason, 'updated_by' => $actor->id]);

            return $goal;
        });
        if ($notify) {
            $this->notify($goal, CrmNotification::TYPE_GOAL_CANCELLED);
        }

        return $this->load($goal);
    }

    private function definition(array $data): array
    {
        if (($data['starts_on'] ?? null) && ($data['due_on'] ?? null)
            && CarbonImmutable::parse($data['due_on'])->lt(CarbonImmutable::parse($data['starts_on']))) {
            throw ValidationException::withMessages(['due_on' => 'La fecha límite debe ser igual o posterior a la fecha inicial.']);
        }

        return collect($data)->only(['title', 'description', 'target_value', 'unit', 'starts_on', 'due_on'])->all();
    }

    private function requireActiveEmployee(Employee $employee): void
    {
        if (! $employee->is_active) {
            throw ValidationException::withMessages(['employee_id' => 'El empleado debe estar activo.']);
        }
    }

    private function requireActiveGoal(Goal $goal): void
    {
        if ($goal->status !== Goal::STATUS_ACTIVE) {
            throw ValidationException::withMessages(['status' => 'La meta debe estar activa.']);
        }
    }

    private function requireMutableUser(Goal $goal, User $actor): void
    {
        abort_unless($actor->employee?->id === $goal->employee_id, 404);
        $this->requireActiveEmployee($actor->employee);
        $this->requireActiveGoal($goal);
    }

    private function notify(Goal $goal, string $type, ?User $specific = null): void
    {
        $user = $specific ?? $goal->employee()->with('user')->first()?->user;
        if (! $user?->is_active) {
            return;
        }
        try {
            $this->notifications->createFor($user, $this->notificationPayload($goal, $type));
        } catch (\Throwable $exception) {
            Log::warning('Goal notification failed.', ['goal_id' => $goal->id, 'type' => $type]);
        }
    }

    private function notifyReassignment(Goal $goal, int $oldEmployeeId): void
    {
        $oldUser = Employee::with('user')->find($oldEmployeeId)?->user;
        if ($oldUser) {
            $this->notify($goal, CrmNotification::TYPE_GOAL_REASSIGNED, $oldUser);
        }
        $this->notify($goal, CrmNotification::TYPE_GOAL_ASSIGNED);
    }

    private function notificationPayload(Goal $goal, string $type): array
    {
        $labels = [CrmNotification::TYPE_GOAL_ASSIGNED => ['Meta asignada', 'Tienes una nueva meta asignada.'],
            CrmNotification::TYPE_GOAL_UPDATED => ['Meta actualizada', 'Una meta asignada fue actualizada.'],
            CrmNotification::TYPE_GOAL_REASSIGNED => ['Meta reasignada', 'Ya no eres responsable de esta meta.'],
            CrmNotification::TYPE_GOAL_CANCELLED => ['Meta cancelada', 'Una meta asignada fue cancelada.']];
        $data = array_filter(['goal_id' => $goal->id, 'title' => $goal->title, 'target_value' => $goal->target_value,
            'unit' => $goal->unit, 'due_on' => $goal->due_on?->toDateString()], fn ($value) => $value !== null);

        return ['type' => $type, 'severity' => $type === CrmNotification::TYPE_GOAL_CANCELLED ? CrmNotification::SEVERITY_WARNING : CrmNotification::SEVERITY_INFO,
            'title' => $labels[$type][0], 'message' => $labels[$type][1], 'reference_type' => 'goal', 'reference_id' => $goal->id,
            'dedupe_key' => "{$type}:{$goal->id}:".sha1(json_encode($data)), 'data' => $data];
    }

    private function relations(): array
    {
        return ['employee:id,name,job_title,specialty,is_active,user_id', 'creator:id,name', 'updater:id,name', 'completer:id,name', 'canceller:id,name'];
    }
}
