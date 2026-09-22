<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeScheduleOverride;
use App\Models\EmployeeWorkSchedule;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmployeeScheduleService
{
    public function createSchedule(array $data, User $user): EmployeeWorkSchedule
    {
        return DB::transaction(function () use ($data, $user) {
            $this->lockEmployees([(int) $data['employee_id']]);
            $this->validateSchedule($data);

            return EmployeeWorkSchedule::create([...$data, 'created_by' => $user->id])->load('employee:id,name,is_active');
        });
    }

    public function updateSchedule(EmployeeWorkSchedule $schedule, array $data): EmployeeWorkSchedule
    {
        return DB::transaction(function () use ($schedule, $data) {
            $values = [...$schedule->only(['employee_id', 'day_of_week', 'starts_at', 'ends_at']),
                'effective_from' => $schedule->effective_from->toDateString(),
                'effective_until' => $schedule->effective_until?->toDateString(),
                ...$data,
            ];
            $this->lockEmployees([(int) $schedule->employee_id, (int) $values['employee_id']]);
            $this->validateSchedule($values, $schedule->id);
            $schedule->update($data);

            return $schedule->fresh()->load('employee:id,name,is_active');
        });
    }

    public function deleteSchedule(EmployeeWorkSchedule $schedule): void
    {
        DB::transaction(function () use ($schedule) {
            $this->lockEmployees([(int) $schedule->employee_id]);
            $schedule->delete();
        });
    }

    public function createOverride(array $data, User $user): EmployeeScheduleOverride
    {
        return DB::transaction(function () use ($data, $user) {
            $this->lockEmployees([(int) $data['employee_id']]);
            $this->validateOverride($data);
            $values = $this->normalizeOverride($data);

            return EmployeeScheduleOverride::create([...$values, 'created_by' => $user->id])->load('employee:id,name,is_active');
        });
    }

    public function updateOverride(EmployeeScheduleOverride $override, array $data): EmployeeScheduleOverride
    {
        return DB::transaction(function () use ($override, $data) {
            $values = [...$override->only(['employee_id', 'type', 'starts_at', 'ends_at', 'reason']), 'date' => $override->date->toDateString(), ...$data];
            $this->lockEmployees([(int) $override->employee_id, (int) $values['employee_id']]);
            $this->validateOverride($values, $override->id);
            $values = $this->normalizeOverride($values);
            $override->update($values);

            return $override->fresh()->load('employee:id,name,is_active');
        });
    }

    public function deleteOverride(EmployeeScheduleOverride $override): void
    {
        DB::transaction(function () use ($override) {
            $this->lockEmployees([(int) $override->employee_id]);
            $override->delete();
        });
    }

    private function validateSchedule(array $data, ?int $ignoreId = null): void
    {
        $startsAt = $this->time($data['starts_at']);
        $endsAt = $this->time($data['ends_at']);
        if ($endsAt <= $startsAt) {
            throw ValidationException::withMessages(['ends_at' => 'La hora final debe ser posterior a la hora inicial y no puede cruzar medianoche.']);
        }
        $effectiveFrom = (string) $data['effective_from'];
        $effectiveUntil = $data['effective_until'] ?? null;
        if ($effectiveUntil && $effectiveUntil < $effectiveFrom) {
            throw ValidationException::withMessages(['effective_until' => 'La vigencia final no puede ser anterior a la inicial.']);
        }
        $overlap = EmployeeWorkSchedule::query()
            ->where('employee_id', $data['employee_id'])
            ->where('day_of_week', $data['day_of_week'])
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->whereDate('effective_from', '<=', $effectiveUntil ?: '9999-12-31')
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $effectiveFrom))
            ->exists();
        if ($overlap) {
            throw ValidationException::withMessages(['starts_at' => 'El intervalo se solapa con otro horario vigente del empleado.']);
        }
    }

    private function validateOverride(array $data, ?int $ignoreId = null): void
    {
        if ($data['type'] === EmployeeScheduleOverride::TYPE_WORKING) {
            if (! ($data['starts_at'] ?? null) || ! ($data['ends_at'] ?? null)) {
                throw ValidationException::withMessages(['starts_at' => 'Un override working requiere hora inicial y final.']);
            }
            if ($this->time($data['ends_at']) <= $this->time($data['starts_at'])) {
                throw ValidationException::withMessages(['ends_at' => 'La hora final debe ser posterior a la inicial y no puede cruzar medianoche.']);
            }
        } elseif (($data['starts_at'] ?? null) !== null || ($data['ends_at'] ?? null) !== null) {
            throw ValidationException::withMessages(['starts_at' => 'Un override non_working debe representar el día completo sin horas.']);
        }
        if (EmployeeScheduleOverride::query()->where('employee_id', $data['employee_id'])->whereDate('date', $data['date'])->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))->exists()) {
            throw ValidationException::withMessages(['date' => 'Ya existe un override para este empleado y fecha.']);
        }
    }

    private function normalizeOverride(array $data): array
    {
        if ($data['type'] === EmployeeScheduleOverride::TYPE_NON_WORKING) {
            $data['starts_at'] = null;
            $data['ends_at'] = null;
        }

        return $data;
    }

    private function lockEmployees(array $ids): void
    {
        $ids = array_values(array_unique($ids));
        sort($ids);
        if (Employee::query()->whereKey($ids)->lockForUpdate()->get(['id'])->count() !== count($ids)) {
            throw ValidationException::withMessages(['employee_id' => 'El empleado seleccionado no existe.']);
        }
    }

    private function time(string $value): string
    {
        return strlen($value) === 5 ? "{$value}:00" : substr($value, 0, 8);
    }
}
