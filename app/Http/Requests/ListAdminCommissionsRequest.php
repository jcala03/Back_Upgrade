<?php

namespace App\Http\Requests;

use App\Models\EmployeeCommission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListAdminCommissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('commissions.view');
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
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'status' => ['nullable', Rule::in(EmployeeCommission::statuses())],
            'order_id' => ['nullable', 'integer', 'exists:orders,id'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'search' => ['nullable', 'string', 'max:160'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
