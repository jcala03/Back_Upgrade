<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\EmployeeScheduleOverride;
use App\Models\EmployeeWorkSchedule;
use App\Models\Task;
use App\Support\Business\BusinessContext;
use App\Support\Employees\AvailabilityResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class EmployeeAvailabilityService
{
    public const REASON_INACTIVE = 'inactive_employee';

    public const REASON_OUTSIDE_SCHEDULE = 'outside_work_schedule';

    public const REASON_NO_SCHEDULE = 'no_work_schedule';

    public const REASON_APPROVED_LEAVE = 'approved_leave';

    public const REASON_TASK_OVERLAP = 'task_overlap';

    public const REASON_APPOINTMENT_OVERLAP = 'appointment_overlap';

    public function check(Employee $employee, CarbonImmutable $startsAt, CarbonImmutable $endsAt, array $exclusions = []): AvailabilityResult
    {
        $startsUtc = $startsAt->utc();
        $endsUtc = $endsAt->utc();
        if ($endsUtc->lessThanOrEqualTo($startsUtc)) {
            throw ValidationException::withMessages(['ends_at' => 'La fecha final debe ser posterior a la inicial.']);
        }
        $localStart = $startsUtc->setTimezone(BusinessContext::TIMEZONE);
        $localEnd = $endsUtc->setTimezone(BusinessContext::TIMEZONE);
        if (! $localStart->isSameDay($localEnd)) {
            throw ValidationException::withMessages(['ends_at' => 'La consulta de disponibilidad debe permanecer dentro del mismo día en Bogotá.']);
        }
        if (! $employee->is_active) {
            return AvailabilityResult::unavailable(self::REASON_INACTIVE);
        }

        $date = $localStart->toDateString();
        $startsTime = $localStart->format('H:i:s');
        $endsTime = $localEnd->format('H:i:s');
        $override = EmployeeScheduleOverride::query()->where('employee_id', $employee->id)->onDate($date)->first();
        $reasonCodes = [];
        if ($override) {
            if ($override->type === EmployeeScheduleOverride::TYPE_NON_WORKING
                || $startsTime < $override->starts_at || $endsTime > $override->ends_at) {
                $reasonCodes[] = self::REASON_OUTSIDE_SCHEDULE;
            }
        } else {
            $schedules = EmployeeWorkSchedule::query()
                ->where('employee_id', $employee->id)
                ->where('day_of_week', $localStart->isoWeekday())
                ->effectiveOn($date)
                ->get(['starts_at', 'ends_at']);
            if ($schedules->isEmpty()) {
                $reasonCodes[] = self::REASON_NO_SCHEDULE;
            } elseif (! $schedules->contains(fn (EmployeeWorkSchedule $schedule) => $startsTime >= $schedule->starts_at && $endsTime <= $schedule->ends_at)) {
                $reasonCodes[] = self::REASON_OUTSIDE_SCHEDULE;
            }
        }

        $leaveOverlap = EmployeeLeave::query()
            ->where('employee_id', $employee->id)
            ->approved()
            ->when($exclusions['leave_id'] ?? null, fn ($query, $id) => $query->whereKeyNot($id))
            ->overlapping($startsUtc->format('Y-m-d H:i:s'), $endsUtc->format('Y-m-d H:i:s'))
            ->exists();
        if ($leaveOverlap) {
            $reasonCodes[] = self::REASON_APPROVED_LEAVE;
        }

        $taskOverlap = Task::query()
            ->where('assigned_employee_id', $employee->id)
            ->blockingAvailability()
            ->when($exclusions['task_id'] ?? null, fn ($query, $id) => $query->whereKeyNot($id))
            ->where('scheduled_starts_at', '<', $endsUtc->format('Y-m-d H:i:s'))
            ->where('scheduled_ends_at', '>', $startsUtc->format('Y-m-d H:i:s'))
            ->exists();
        if ($taskOverlap) {
            $reasonCodes[] = self::REASON_TASK_OVERLAP;
        }

        $appointmentOverlap = Appointment::query()
            ->where('responsible_employee_id', $employee->id)
            ->blockingAvailability()
            ->when($exclusions['appointment_id'] ?? null, fn ($query, $id) => $query->whereKeyNot($id))
            ->where('starts_at', '<', $endsUtc->format('Y-m-d H:i:s'))
            ->where('ends_at', '>', $startsUtc->format('Y-m-d H:i:s'))
            ->exists();
        if ($appointmentOverlap) {
            $reasonCodes[] = self::REASON_APPOINTMENT_OVERLAP;
        }

        return $reasonCodes === [] ? AvailabilityResult::available() : AvailabilityResult::unavailable(...$reasonCodes);
    }

    public function availableEmployees(Collection $employees, CarbonImmutable $startsAt, CarbonImmutable $endsAt): Collection
    {
        return $employees->mapWithKeys(fn (Employee $employee) => [$employee->id => $this->check($employee, $startsAt, $endsAt)]);
    }
}
