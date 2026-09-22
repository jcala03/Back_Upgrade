<?php

namespace App\Http\Requests\Reports;

use Illuminate\Validation\Rule;

class InventoryReportRequest extends ReportRequest
{
    public function rules(): array
    {
        return [...$this->commonRules(false), 'status' => ['nullable', Rule::in(['all', 'normal', 'low', 'out'])], 'product_id' => ['nullable', 'integer', 'exists:products,id'], 'product_variant_id' => ['nullable', 'integer', 'exists:product_variants,id'], 'sort' => ['nullable', Rule::in(['stock', 'sku', 'name'])]];
    }
}
