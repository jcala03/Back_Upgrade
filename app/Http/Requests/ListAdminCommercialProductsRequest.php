<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListAdminCommercialProductsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('orders.create');
    }

    public function rules(): array
    {
        return [
            'branch_id' => [
                'required',
                'integer',
                Rule::exists('branches', 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
            'search' => ['nullable', 'string', 'min:2', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
