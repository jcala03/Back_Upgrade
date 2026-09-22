<?php

namespace App\Http\Requests;

use App\Support\Business\BusinessContext;

class StoreMyQuotationRequest extends StoreQuotationRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('quotations.create_own');
    }

    public function rules(): array
    {
        return $this->quotationRules(
            ['nullable', 'date', 'after_or_equal:'.BusinessContext::today()->toDateString()],
            ['prohibited'],
            ['prohibited'],
        );
    }
}
