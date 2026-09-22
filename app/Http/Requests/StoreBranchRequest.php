<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('branches.create');
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];
        if (is_string($this->input('code'))) {
            $normalized['code'] = Str::upper(trim($this->input('code')));
        }
        if (is_string($this->input('slug'))) {
            $normalized['slug'] = Str::slug(trim($this->input('slug')));
        }
        if (is_string($this->input('country_code'))) {
            $normalized['country_code'] = Str::upper(trim($this->input('country_code')));
        }

        $this->merge($normalized);
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:12', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('branches', 'code')],
            'slug' => ['required', 'string', 'max:120', 'alpha_dash:ascii', Rule::unique('branches', 'slug')],
            'name' => ['required', 'string', 'max:160'],
            'city' => ['required', 'string', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
            'ecommerce_priority' => ['sometimes', 'integer', 'min:0'],
            'country_code' => ['nullable', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
            'state' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'address_line1' => ['nullable', 'string', 'max:220'],
            'address_line2' => ['nullable', 'string', 'max:220'],
        ];
    }
}
