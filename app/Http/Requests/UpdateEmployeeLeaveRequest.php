<?php

namespace App\Http\Requests;

class UpdateEmployeeLeaveRequest extends StoreEmployeeLeaveRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('employee_leaves.update');
    }

    public function rules(): array
    {
        return self::leaveRules(true);
    }
}
