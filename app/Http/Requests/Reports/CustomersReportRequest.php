<?php

namespace App\Http\Requests\Reports;

use Illuminate\Validation\Rule;

class CustomersReportRequest extends ReportRequest
{
    public function rules(): array
    {
        return [...$this->commonRules(), 'is_active' => ['nullable', 'boolean'], 'buyer' => ['nullable', Rule::in(['all', 'yes', 'no'])], 'sort' => ['nullable', Rule::in(['created_at', 'orders_count', 'total_spent', 'last_purchase_at'])]];
    }
}
