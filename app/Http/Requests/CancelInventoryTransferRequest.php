<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CancelInventoryTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('inventory_transfers.cancel');
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => trim($this->input('reason'))]);
        }
    }
}
