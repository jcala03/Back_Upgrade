<?php

namespace App\Http\Requests;

class StoreMySalePaymentRequest extends StoreOrderPaymentRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('payments.create_own');
    }

    public function rules(): array
    {
        return [
            ...parent::rules(),
            'status' => ['prohibited'],
            'order_id' => ['prohibited'],
            'created_by' => ['prohibited'],
            'metadata' => ['prohibited'],
        ];
    }
}
