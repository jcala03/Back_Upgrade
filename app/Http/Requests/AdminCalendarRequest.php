<?php

namespace App\Http\Requests;

class AdminCalendarRequest extends CalendarRangeRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('calendar.view');
    }

    public function rules(): array
    {
        return [...parent::rules(), 'branch_id' => ['sometimes', 'integer', 'exists:branches,id'],
            'employee_ids' => ['sometimes', 'array', 'max:100'], 'employee_ids.*' => ['integer', 'distinct', 'exists:employees,id']];
    }
}
