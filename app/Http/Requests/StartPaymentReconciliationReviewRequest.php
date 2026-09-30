<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StartPaymentReconciliationReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('payments.reconcile');
    }

    public function validationData(): array
    {
        return ['idempotency_key' => $this->header('Idempotency-Key')];
    }

    public function rules(): array
    {
        return ['idempotency_key' => ['required', 'string', 'max:191', 'regex:/^[\x21-\x7E]+$/']];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->query->count() !== 0 || $this->json()->all() !== []) {
                $validator->errors()->add('body', 'El cuerpo debe ser un objeto vacío.');
            }
        });
    }
}
