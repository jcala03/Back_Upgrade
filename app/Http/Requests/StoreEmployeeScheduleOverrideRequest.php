<?php

namespace App\Http\Requests;

use App\Models\EmployeeScheduleOverride;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeScheduleOverrideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('employee_schedules.update');
    }

    public function rules(): array
    {
        return self::overrideRules();
    }

    public static function overrideRules(bool $partial = false): array
    {
        $presence = $partial ? 'sometimes' : 'required';

        return [
            'employee_id' => [$presence, 'integer', 'exists:employees,id'],
            'date' => [$presence, 'date_format:Y-m-d'],
            'type' => [$presence, Rule::in(EmployeeScheduleOverride::types())],
            'starts_at' => ['nullable', 'date_format:H:i'],
            'ends_at' => ['nullable', 'date_format:H:i'],
            'reason' => ['nullable', 'string', 'max:255'],
            'created_by' => ['prohibited'],
        ];
    }
}
