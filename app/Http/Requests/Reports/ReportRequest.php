<?php

namespace App\Http\Requests\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

abstract class ReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('reports.view');
    }

    protected function commonRules(bool $period = true): array
    {
        return [
            ...($period ? ['date_from' => ['nullable', 'date_format:Y-m-d'], 'date_to' => ['nullable', 'date_format:Y-m-d']] : []),
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['nullable', 'string'],
            'direction' => ['nullable', 'in:asc,desc'],
            'search' => ['nullable', 'string', 'max:160'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->filled('date_from') || ! $this->filled('date_to')) {
                return;
            }
            if ($validator->errors()->has('date_from') || $validator->errors()->has('date_to')) {
                return;
            }
            $from = CarbonImmutable::parse($this->input('date_from'));
            $to = CarbonImmutable::parse($this->input('date_to'));
            if ($from->gt($to)) {
                $validator->errors()->add('date_to', 'La fecha final debe ser igual o posterior a la fecha inicial.');
            } elseif ($from->diffInDays($to) > 365) {
                $validator->errors()->add('date_to', 'El rango máximo permitido es de 366 días.');
            }
        });
    }

    public function filters(): array
    {
        return $this->validated();
    }
}
