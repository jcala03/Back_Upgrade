<?php

namespace App\Http\Requests;

use App\Services\PaymentReconciliationResolutionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePaymentReconciliationDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('payments.reconcile');
    }

    protected function prepareForValidation(): void
    {
        foreach (['decision', 'justification', 'evidence_reference'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
    }

    public function validationData(): array
    {
        return [...parent::validationData(), 'idempotency_key' => $this->header('Idempotency-Key')];
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'max:191', 'regex:/^[\x21-\x7E]+$/'],
            'decision' => ['required', Rule::in(PaymentReconciliationResolutionService::decisions())],
            'justification' => ['required', 'string', 'min:10', 'max:2000'],
            'evidence_reference' => ['nullable', 'string', 'max:255'],
            'canonical_payment_id' => ['nullable', 'integer', 'exists:payments,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->query->count() !== 0) {
                $validator->errors()->add('query', 'No se permiten parámetros de consulta.');
            }
            $allowed = ['decision', 'justification', 'evidence_reference', 'canonical_payment_id'];
            if (array_diff(array_keys($this->json()->all()), $allowed) !== []) {
                $validator->errors()->add('body', 'El cuerpo contiene campos no permitidos.');
            }
        });
    }
}
