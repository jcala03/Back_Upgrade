<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class UpdateQuotationRequest extends StoreQuotationRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('quotations.update');
    }

    public function rules(): array
    {
        return $this->quotationRules(
            ['nullable', 'date'],
            ['sometimes', 'required', 'integer', Rule::exists('branches', 'id')],
            ['sometimes', 'nullable', 'integer', Rule::exists('employees', 'id')],
        );
    }
}
