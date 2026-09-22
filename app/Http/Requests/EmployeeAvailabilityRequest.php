<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EmployeeAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('employee_availability.view');
    }

    public function rules(): array
    {
        return [
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date'],
            'employee_ids' => ['sometimes', 'array', 'max:100'],
            'employee_ids.*' => ['integer', 'distinct', 'exists:employees,id'],
        ];
    }
}
