<?php

namespace App\Http\Requests;

use App\Models\InventoryMovement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInventoryMovementRequest extends FormRequest
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
            'type' => [
                'required',
                Rule::in([
                    InventoryMovement::TYPE_ENTRY,
                    InventoryMovement::TYPE_EXIT,
                    InventoryMovement::TYPE_ADJUSTMENT,
                ]),
            ],
            'quantity' => [
                Rule::requiredIf(fn () => in_array($this->input('type'), [
                    InventoryMovement::TYPE_ENTRY,
                    InventoryMovement::TYPE_EXIT,
                ], true)),
                Rule::prohibitedIf(fn () => $this->input('type') === InventoryMovement::TYPE_ADJUSTMENT),
                'nullable',
                'integer',
                'min:1',
            ],
            'new_stock' => [
                Rule::requiredIf(fn () => $this->input('type') === InventoryMovement::TYPE_ADJUSTMENT),
                Rule::prohibitedIf(fn () => in_array($this->input('type'), [
                    InventoryMovement::TYPE_ENTRY,
                    InventoryMovement::TYPE_EXIT,
                ], true)),
                'nullable',
                'integer',
                'min:0',
            ],
            'reason' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
