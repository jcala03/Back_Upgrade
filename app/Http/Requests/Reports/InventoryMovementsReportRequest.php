<?php

namespace App\Http\Requests\Reports;

use App\Models\InventoryMovement;
use Illuminate\Validation\Rule;

class InventoryMovementsReportRequest extends ReportRequest
{
    public function rules(): array
    {
        return [...$this->commonRules(), 'type' => ['nullable', Rule::in(InventoryMovement::types())], 'product_id' => ['nullable', 'integer', 'exists:products,id'], 'product_variant_id' => ['nullable', 'integer', 'exists:product_variants,id'], 'created_by' => ['nullable', 'integer', 'exists:users,id'], 'reference_type' => ['nullable', 'string', 'max:160'], 'sort' => ['nullable', Rule::in(['created_at', 'quantity_delta'])]];
    }
}
