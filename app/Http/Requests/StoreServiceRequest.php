<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('services.create');
    }

    public function rules(): array
    {
        return self::serviceRules();
    }

    public static function serviceRules(bool $partial = false): array
    {
        $presence = $partial ? 'sometimes' : 'required';

        return [
            'service_category_id' => [$presence, 'integer', 'exists:service_categories,id'],
            'name' => [$presence, 'string', 'max:160'],
            'description' => ['nullable', 'string'],
            'price' => [$presence, 'integer', 'min:0'],
            'cost' => ['nullable', 'integer', 'min:0'],
            'estimated_duration_minutes' => [$presence, 'integer', 'min:5', 'max:1440'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'stock' => ['prohibited'],
            'sku' => ['prohibited'],
            'technician_id' => ['prohibited'],
            'unit_price' => ['prohibited'],
        ];
    }
}
