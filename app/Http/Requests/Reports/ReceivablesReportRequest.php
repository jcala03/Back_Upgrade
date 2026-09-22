<?php

namespace App\Http\Requests\Reports;

use Illuminate\Validation\Rule;

class ReceivablesReportRequest extends ReportRequest
{
    public function rules(): array
    {
        return [...$this->commonRules(), 'date_from' => ['nullable', 'required_with:date_to', 'date_format:Y-m-d'], 'date_to' => ['nullable', 'required_with:date_from', 'date_format:Y-m-d'], 'customer_id' => ['nullable', 'integer', 'exists:customers,id'], 'created_by' => ['nullable', 'integer', 'exists:users,id'], 'balance_status' => ['nullable', Rule::in(['unpaid', 'partial'])], 'sort' => ['nullable', Rule::in(['outstanding', 'confirmed_at', 'days_outstanding'])]];
    }
}
