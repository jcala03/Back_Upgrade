<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateInventoryMinimumRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('inventory.update');
    }

    public function rules(): array
    {
        return [
            'branch_id' => [
                'required',
                'integer',
                Rule::exists('branches', 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'product_variant_id' => [
                'nullable',
                'integer',
                Rule::exists('product_variants', 'id')
                    ->where(fn ($query) => $query->where('product_id', $this->input('product_id'))),
            ],
            'minimum_quantity' => ['required', 'integer', 'min:0'],
        ];
    }
}
