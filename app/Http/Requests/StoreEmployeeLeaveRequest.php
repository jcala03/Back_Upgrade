<?php

namespace App\Http\Requests;

use App\Models\EmployeeLeave;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeLeaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('employee_leaves.create');
    }

    public function rules(): array
    {
        return self::leaveRules();
    }

    public static function leaveRules(bool $partial = false): array
    {
        $presence = $partial ? 'sometimes' : 'required';

        return [
            'employee_id' => [$presence, 'integer', 'exists:employees,id'],
            'type' => [$presence, Rule::in(EmployeeLeave::types())],
            'starts_at' => [$presence, 'date'],
            'ends_at' => [$presence, 'date'],
            'reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'status' => ['prohibited'],
            'approved_by' => ['prohibited'],
            'approved_at' => ['prohibited'],
            'cancelled_by' => ['prohibited'],
            'cancelled_at' => ['prohibited'],
        ];
    }
}
