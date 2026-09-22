<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInventoryTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('inventory_transfers.create');
    }

    public function rules(): array
    {
        $activeBranch = fn () => Rule::exists('branches', 'id')
            ->where(fn ($query) => $query->where('is_active', true));

        return [
            'source_branch_id' => [
                'required', 'integer', 'different:destination_branch_id', $activeBranch(),
            ],
            'destination_branch_id' => [
                'required', 'integer', 'different:source_branch_id', $activeBranch(),
            ],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.product_variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string'],
            'client_request_key' => ['nullable', 'string', 'max:191'],
        ];
    }
}
