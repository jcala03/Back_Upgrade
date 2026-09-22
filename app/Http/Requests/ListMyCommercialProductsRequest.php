<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListMyCommercialProductsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('orders.create_own');
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['prohibited'],
            'search' => ['nullable', 'string', 'min:2', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
