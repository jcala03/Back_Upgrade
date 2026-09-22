<?php

namespace App\Http\Requests;

class UpdateMyQuotationRequest extends StoreMyQuotationRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('quotations.update_own');
    }

    public function rules(): array
    {
        return $this->quotationRules(
            ['nullable', 'date'],
            ['prohibited'],
            ['prohibited'],
        );
    }
}
