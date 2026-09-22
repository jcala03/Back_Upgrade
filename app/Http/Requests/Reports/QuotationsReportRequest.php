<?php

namespace App\Http\Requests\Reports;

use App\Models\Quotation;
use Illuminate\Validation\Rule;

class QuotationsReportRequest extends ReportRequest
{
    public function rules(): array
    {
        return [...$this->commonRules(), 'status' => ['nullable', Rule::in(Quotation::statuses())], 'customer_id' => ['nullable', 'integer', 'exists:customers,id'], 'created_by' => ['nullable', 'integer', 'exists:users,id'], 'conversion' => ['nullable', Rule::in(['all', 'converted', 'not_converted'])], 'sort' => ['nullable', Rule::in(['created_at', 'valid_until', 'total', 'converted_at'])]];
    }
}
