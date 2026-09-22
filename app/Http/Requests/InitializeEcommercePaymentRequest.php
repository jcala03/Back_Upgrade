<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class InitializeEcommercePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function validationData(): array
    {
        return ['idempotency_key' => $this->header('Idempotency-Key')];
    }

    public function rules(): array
    {
        return ['idempotency_key' => ['required', 'string', 'max:255', 'regex:/^[\x21-\x7E]+$/']];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $body = $this->getContent();
            $decoded = json_decode($body);
            if ($this->query->count() !== 0 || $this->all() !== []
                || ($body !== '' && (! is_object($decoded) || (array) $decoded !== []))) {
                $validator->errors()->add('body', 'El cuerpo del inicio de pago debe ser un objeto vacío.');
            }
        });
    }
}
