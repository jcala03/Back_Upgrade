<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreServiceCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('services.create');
    }

    public function rules(): array
    {
        return self::categoryRules();
    }

    public static function categoryRules(bool $partial = false): array
    {
        return [
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:160'],
            'description' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
