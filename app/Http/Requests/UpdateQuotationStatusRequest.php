<?php

namespace App\Http\Requests;

use App\Models\Quotation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateQuotationStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('quotations.update');
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([Quotation::STATUS_SENT, Quotation::STATUS_REJECTED])],
            'reason' => ['nullable', 'string', 'max:1200'],
        ];
    }
}
