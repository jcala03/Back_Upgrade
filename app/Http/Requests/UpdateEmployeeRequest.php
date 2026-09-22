<?php

namespace App\Http\Requests;

class UpdateEmployeeRequest extends StoreEmployeeRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('employees.update');
    }

    public function rules(): array
    {
        return self::employeeRules(true, $this->route('employee')?->id, false);
    }
}
