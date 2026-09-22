<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('customers.create');
    }

    public function rules(): array
    {
        return self::customerRules();
    }

    public static function customerRules(bool $partial = false): array
    {
        return [
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:160'],
            'document' => ['nullable', 'string', 'max:80'],
            'city' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:220'],
            'notes' => ['nullable', 'string', 'max:1200'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
