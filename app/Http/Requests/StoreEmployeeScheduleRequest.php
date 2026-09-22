<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreEmployeeScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('employee_schedules.update');
    }

    public function rules(): array
    {
        return self::scheduleRules();
    }

    public static function scheduleRules(bool $partial = false): array
    {
        $presence = $partial ? 'sometimes' : 'required';

        return [
            'employee_id' => [$presence, 'integer', 'exists:employees,id'],
            'day_of_week' => [$presence, 'integer', 'between:1,7'],
            'starts_at' => [$presence, 'date_format:H:i'],
            'ends_at' => [$presence, 'date_format:H:i'],
            'effective_from' => [$presence, 'date_format:Y-m-d'],
            'effective_until' => ['nullable', 'date_format:Y-m-d'],
            'created_by' => ['prohibited'],
        ];
    }
}
