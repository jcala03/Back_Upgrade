<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('branches.update');
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];
        if ($this->has('slug') && is_string($this->input('slug'))) {
            $normalized['slug'] = Str::slug(trim($this->input('slug')));
        }
        if ($this->has('country_code') && is_string($this->input('country_code'))) {
            $normalized['country_code'] = Str::upper(trim($this->input('country_code')));
        }

        $this->merge($normalized);
    }

    public function rules(): array
    {
        return [
            'code' => ['prohibited'],
            'slug' => [
                'sometimes', 'required', 'string', 'max:120', 'alpha_dash:ascii',
                Rule::unique('branches', 'slug')->ignore($this->route('branch')?->id),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:160'],
            'city' => ['sometimes', 'required', 'string', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
            'ecommerce_priority' => ['sometimes', 'integer', 'min:0'],
            'country_code' => ['sometimes', 'nullable', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
            'state' => ['sometimes', 'nullable', 'string', 'max:120'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'address_line1' => ['sometimes', 'nullable', 'string', 'max:220'],
            'address_line2' => ['sometimes', 'nullable', 'string', 'max:220'],
        ];
    }
}
