<?php

namespace App\Http\Requests;

use App\Models\Quotation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListMyQuotationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('quotations.view_own');
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:160'],
            'status' => ['nullable', Rule::in(Quotation::statuses())],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'branch_id' => ['prohibited'],
            'sales_employee_id' => ['prohibited'],
            'created_by' => ['prohibited'],
            'updated_by' => ['prohibited'],
            'converted_by' => ['prohibited'],
        ];
    }
}
