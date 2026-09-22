<?php

namespace App\Http\Requests\Reports;

use App\Models\Order;
use Illuminate\Validation\Rule;

class ProductsReportRequest extends ReportRequest
{
    public function rules(): array
    {
        return [...$this->commonRules(), 'origin' => ['nullable', Rule::in(Order::origins())], 'product_id' => ['nullable', 'integer', 'exists:products,id'], 'product_variant_id' => ['nullable', 'integer', 'exists:product_variants,id'], 'group_by' => ['nullable', Rule::in(['product', 'sku'])], 'sort' => ['nullable', Rule::in(['quantity', 'revenue', 'orders_count'])]];
    }
}
