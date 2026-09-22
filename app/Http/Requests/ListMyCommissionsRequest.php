<?php

namespace App\Http\Requests;

use App\Models\EmployeeCommission;
use App\Models\UserCapability;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListMyCommissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission(UserCapability::COMMISSIONS_VIEW_OWN);
    }

    protected function prepareForValidation(): void
    {
        $aliases = [];
        if (! $this->has('date_from') && $this->has('from')) {
            $aliases['date_from'] = $this->input('from');
        }
        if (! $this->has('date_to') && $this->has('to')) {
            $aliases['date_to'] = $this->input('to');
        }
        $this->merge($aliases);
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::in(EmployeeCommission::statuses())],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'employee_id' => ['prohibited'],
            'branch_id' => ['prohibited'],
            'sales_employee_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'order_id' => ['prohibited'],
            'product_id' => ['prohibited'],
            'search' => ['prohibited'],
        ];
    }
}
