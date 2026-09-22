<?php

namespace App\Http\Requests;

use App\Support\Business\BusinessContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class MyBusinessOverviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_active
            && (bool) $this->user()?->hasPermission('business_overview.view');
    }

    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'branch_id' => ['prohibited'],
            'employee_id' => ['prohibited'],
            'sales_employee_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'financials' => ['prohibited'],
            'include_costs' => ['prohibited'],
            'include_other_branches' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (array_diff(array_keys($this->query()), ['from', 'to']) as $parameter) {
                if (! $validator->errors()->has($parameter)) {
                    $validator->errors()->add($parameter, 'Este parámetro no está permitido.');
                }
            }

            if ($validator->errors()->has('from') || $validator->errors()->has('to')) {
                return;
            }

            $month = BusinessContext::today()->startOfMonth();
            $from = CarbonImmutable::parse($this->input('from', $month->toDateString()), BusinessContext::TIMEZONE);
            $to = CarbonImmutable::parse($this->input('to', $month->endOfMonth()->toDateString()), BusinessContext::TIMEZONE);

            if ($from->gt($to)) {
                $validator->errors()->add('to', 'La fecha final debe ser igual o posterior a la fecha inicial.');
            } elseif ($from->diffInDays($to) > 365) {
                $validator->errors()->add('to', 'El rango máximo permitido es de 366 días.');
            }
        });
    }
}
