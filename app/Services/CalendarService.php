<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\EmployeeLeave;
use App\Models\EmployeeScheduleOverride;
use App\Models\EmployeeWorkSchedule;
use App\Models\Task;
use App\Support\Business\BusinessContext;
use App\Support\Calendar\CalendarEvent;
use Carbon\CarbonImmutable;

class CalendarService
{
    public const TYPE_APPOINTMENT = 'appointment';

    public const TYPE_TASK = 'task';

    public const TYPE_LEAVE = 'leave';

    public const TYPE_WORK_SCHEDULE = 'work_schedule';

    public const TYPE_SCHEDULE_OVERRIDE = 'schedule_override';

    public const MAX_RANGE_DAYS = 31;

    public static function types(): array
    {
        return [self::TYPE_APPOINTMENT, self::TYPE_TASK, self::TYPE_LEAVE, self::TYPE_WORK_SCHEDULE, self::TYPE_SCHEDULE_OVERRIDE];
    }

    public function events(array $filters, ?int $ownedEmployeeId = null): array
    {
        $from = CarbonImmutable::parse($filters['from'], BusinessContext::TIMEZONE)->utc();
        $to = CarbonImmutable::parse($filters['to'], BusinessContext::TIMEZONE)->utc();
        $employeeIds = $ownedEmployeeId ? [$ownedEmployeeId] : ($filters['employee_ids'] ?? null);
        $types = $filters['types'] ?? self::types();
        $statuses = $filters['statuses'] ?? null;
        $branchId = $ownedEmployeeId ? null : ($filters['branch_id'] ?? null);
        $events = collect();

        if (in_array(self::TYPE_APPOINTMENT, $types, true)) {
            $events->push(...$this->appointments($from, $to, $employeeIds, $statuses, $ownedEmployeeId !== null, $branchId));
        }
        if (in_array(self::TYPE_TASK, $types, true)) {
            $events->push(...$this->tasks($from, $to, $employeeIds, $statuses, $branchId));
        }
        if ($branchId === null && in_array(self::TYPE_LEAVE, $types, true)) {
            $events->push(...$this->leaves($from, $to, $employeeIds));
        }
        if ($branchId === null && array_intersect([self::TYPE_WORK_SCHEDULE, self::TYPE_SCHEDULE_OVERRIDE], $types)) {
            $events->push(...$this->schedules($from, $to, $employeeIds, $types));
        }

        return $events->sort(fn (CalendarEvent $left, CalendarEvent $right) => [
            $left->startsAt->getTimestamp(), $left->type, $left->sourceId, $left->id,
        ] <=> [
            $right->startsAt->getTimestamp(), $right->type, $right->sourceId, $right->id,
        ])->values()->map(fn (CalendarEvent $event) => $event->toArray(BusinessContext::TIMEZONE))->all();
    }

    private function appointments(CarbonImmutable $from, CarbonImmutable $to, ?array $employeeIds, ?array $statuses, bool $personal, ?int $branchId): array
    {
        return Appointment::query()->select(['id', 'customer_id', 'customer_vehicle_id', 'service_id', 'responsible_employee_id', 'branch_id',
            'source', 'status', 'title', 'contact_name', 'vehicle_description', 'service_name', 'starts_at', 'ends_at'])
            ->with(['responsibleEmployee:id,name', 'branch:id,code,name'])->when($employeeIds !== null, fn ($q) => $q->whereIn('responsible_employee_id', $employeeIds))
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->when($statuses, fn ($q) => $q->whereIn('status', $statuses))
            ->where('starts_at', '<', $to)->where('ends_at', '>', $from)->get()
            ->map(function (Appointment $appointment) use ($personal) {
                $meta = $personal
                    ? ['service_name' => $appointment->service_name, 'vehicle_description' => $appointment->vehicle_description,
                        'contact_name' => $appointment->contact_name]
                    : ['source' => $appointment->source, 'customer_id' => $appointment->customer_id,
                        'customer_vehicle_id' => $appointment->customer_vehicle_id, 'service_id' => $appointment->service_id,
                        'contact_name' => $appointment->contact_name, 'vehicle_description' => $appointment->vehicle_description,
                        'service_name' => $appointment->service_name];

                return new CalendarEvent("appointment:{$appointment->id}", self::TYPE_APPOINTMENT, $appointment->id,
                    $appointment->title, $appointment->starts_at, $appointment->ends_at, false, $appointment->status,
                    $this->employee($appointment->responsibleEmployee), array_filter($meta, fn ($value) => $value !== null),
                    $this->branch($appointment->branch));
            })->all();
    }

    private function tasks(CarbonImmutable $from, CarbonImmutable $to, ?array $employeeIds, ?array $statuses, ?int $branchId): array
    {
        return Task::query()->select(['id', 'assigned_employee_id', 'branch_id', 'title', 'priority', 'status', 'due_at', 'scheduled_starts_at', 'scheduled_ends_at'])
            ->with(['employee:id,name', 'branch:id,code,name'])->when($employeeIds !== null, fn ($q) => $q->whereIn('assigned_employee_id', $employeeIds))
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->when($statuses, fn ($q) => $q->whereIn('status', $statuses))->whereNotNull('scheduled_starts_at')->whereNotNull('scheduled_ends_at')
            ->where('scheduled_starts_at', '<', $to)->where('scheduled_ends_at', '>', $from)->get()
            ->map(fn (Task $task) => new CalendarEvent("task:{$task->id}", self::TYPE_TASK, $task->id, $task->title,
                $task->scheduled_starts_at, $task->scheduled_ends_at, false, $task->status, $this->employee($task->employee),
                array_filter(['priority' => $task->priority, 'due_at' => $task->due_at?->setTimezone(BusinessContext::TIMEZONE)->toIso8601String()], fn ($value) => $value !== null),
                $this->branch($task->branch)))->all();
    }

    private function leaves(CarbonImmutable $from, CarbonImmutable $to, ?array $employeeIds): array
    {
        $labels = [EmployeeLeave::TYPE_VACATION => 'Vacaciones', EmployeeLeave::TYPE_PERMISSION => 'Permiso',
            EmployeeLeave::TYPE_SICK_LEAVE => 'Incapacidad', EmployeeLeave::TYPE_ABSENCE => 'Ausencia', EmployeeLeave::TYPE_OTHER => 'No disponible'];

        return EmployeeLeave::query()->select(['id', 'employee_id', 'type', 'starts_at', 'ends_at', 'status'])
            ->with('employee:id,name')->approved()->when($employeeIds !== null, fn ($q) => $q->whereIn('employee_id', $employeeIds))
            ->where('starts_at', '<', $to)->where('ends_at', '>', $from)->get()
            ->map(fn (EmployeeLeave $leave) => new CalendarEvent("leave:{$leave->id}", self::TYPE_LEAVE, $leave->id,
                $labels[$leave->type] ?? 'No disponible', $leave->starts_at, $leave->ends_at, false, $leave->status,
                $this->employee($leave->employee), ['leave_type' => $leave->type]))->all();
    }

    private function schedules(CarbonImmutable $from, CarbonImmutable $to, ?array $employeeIds, array $types): array
    {
        $localFrom = $from->setTimezone(BusinessContext::TIMEZONE);
        $localTo = $to->setTimezone(BusinessContext::TIMEZONE);
        $firstDate = $localFrom->startOfDay();
        $lastDate = $localTo->startOfDay();
        $schedules = EmployeeWorkSchedule::query()->select(['id', 'employee_id', 'day_of_week', 'starts_at', 'ends_at', 'effective_from', 'effective_until'])
            ->with('employee:id,name')->when($employeeIds !== null, fn ($q) => $q->whereIn('employee_id', $employeeIds))
            ->whereDate('effective_from', '<=', $lastDate->toDateString())
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $firstDate->toDateString()))->get();
        $overrides = EmployeeScheduleOverride::query()->select(['id', 'employee_id', 'date', 'type', 'starts_at', 'ends_at'])
            ->with('employee:id,name')->when($employeeIds !== null, fn ($q) => $q->whereIn('employee_id', $employeeIds))
            ->whereBetween('date', [$firstDate->toDateString(), $lastDate->toDateString()])->get();
        $overrideLookup = $overrides->keyBy(fn (EmployeeScheduleOverride $override) => "{$override->employee_id}:{$override->date->toDateString()}");
        $events = collect();
        for ($date = $firstDate; $date->lessThan($localTo); $date = $date->addDay()) {
            $dateString = $date->toDateString();
            if (in_array(self::TYPE_WORK_SCHEDULE, $types, true)) {
                foreach ($schedules as $schedule) {
                    if ($schedule->day_of_week !== $date->isoWeekday()
                        || $schedule->effective_from->toDateString() > $dateString
                        || ($schedule->effective_until && $schedule->effective_until->toDateString() < $dateString)
                        || $overrideLookup->has("{$schedule->employee_id}:{$dateString}")) {
                        continue;
                    }
                    $start = CarbonImmutable::parse("{$dateString} {$schedule->starts_at}", BusinessContext::TIMEZONE);
                    $end = CarbonImmutable::parse("{$dateString} {$schedule->ends_at}", BusinessContext::TIMEZONE);
                    if ($this->intersects($start, $end, $from, $to)) {
                        $events->push(new CalendarEvent("schedule:{$schedule->id}:{$dateString}", self::TYPE_WORK_SCHEDULE,
                            $schedule->id, 'Horario laboral', $start, $end, false, null, $this->employee($schedule->employee)));
                    }
                }
            }
        }
        if (in_array(self::TYPE_SCHEDULE_OVERRIDE, $types, true)) {
            foreach ($overrides as $override) {
                $dateString = $override->date->toDateString();
                $allDay = $override->type === EmployeeScheduleOverride::TYPE_NON_WORKING;
                $start = $allDay ? CarbonImmutable::parse($dateString, BusinessContext::TIMEZONE)->startOfDay()
                    : CarbonImmutable::parse("{$dateString} {$override->starts_at}", BusinessContext::TIMEZONE);
                $end = $allDay ? $start->addDay() : CarbonImmutable::parse("{$dateString} {$override->ends_at}", BusinessContext::TIMEZONE);
                if ($this->intersects($start, $end, $from, $to)) {
                    $events->push(new CalendarEvent("override:{$override->id}", self::TYPE_SCHEDULE_OVERRIDE, $override->id,
                        $allDay ? 'No laborable' : 'Horario excepcional', $start, $end, $allDay, null,
                        $this->employee($override->employee), ['override_type' => $override->type]));
                }
            }
        }

        return $events->all();
    }

    private function intersects(CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $from, CarbonImmutable $to): bool
    {
        return $start->utc()->lessThan($to) && $end->utc()->greaterThan($from);
    }

    private function employee(mixed $employee): ?array
    {
        return $employee ? ['id' => $employee->id, 'name' => $employee->name] : null;
    }

    private function branch(mixed $branch): ?array
    {
        return $branch ? ['id' => $branch->id, 'code' => $branch->code, 'name' => $branch->name] : null;
    }
}
