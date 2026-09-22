<?php

namespace App\Http\Requests;

use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrderPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('payments.create');
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'integer', 'min:1'], 'method' => ['required', Rule::in(Payment::methods())],
            'status' => ['nullable', Rule::in(Payment::statuses())], 'provider' => ['nullable', 'string', 'max:120'],
            'reference' => ['nullable', 'string', 'max:160'], 'transaction_id' => ['nullable', 'string', 'max:160'],
            'notes' => ['nullable', 'string', 'max:1200'], 'paid_at' => ['nullable', 'date'],
        ];
    }
}
