<?php

namespace App\Http\Requests\Reports;

use App\Models\Order;
use Illuminate\Validation\Rule;

class SalesReportRequest extends ReportRequest
{
    public function rules(): array
    {
        return [...$this->commonRules(), 'origin' => ['nullable', Rule::in(Order::origins())], 'payment_status' => ['nullable', Rule::in([Order::PAYMENT_UNPAID, Order::PAYMENT_PARTIAL, Order::PAYMENT_PAID, Order::PAYMENT_REFUNDED])], 'customer_id' => ['nullable', 'integer', 'exists:customers,id'], 'created_by' => ['nullable', 'integer', 'exists:users,id'], 'product_id' => ['nullable', 'integer', 'exists:products,id'], 'product_variant_id' => ['nullable', 'integer', 'exists:product_variants,id'], 'sort' => ['nullable', Rule::in(['confirmed_at', 'total'])]];
    }
}
