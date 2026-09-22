<?php

namespace App\Http\Requests\Reports;

use App\Models\Payment;
use Illuminate\Validation\Rule;

class PaymentsReportRequest extends ReportRequest
{
    public function rules(): array
    {
        return [...$this->commonRules(), 'method' => ['nullable', Rule::in(Payment::methods())], 'status' => ['nullable', Rule::in(Payment::statuses())], 'order_id' => ['nullable', 'integer', 'exists:orders,id'], 'created_by' => ['nullable', 'integer', 'exists:users,id'], 'sort' => ['nullable', Rule::in(['paid_at', 'amount'])]];
    }
}
