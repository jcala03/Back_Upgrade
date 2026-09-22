<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCustomerVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('customers.create');
    }

    public function rules(): array
    {
        return self::vehicleRules();
    }

    public static function vehicleRules(bool $partial = false): array
    {
        $optional = $partial ? ['sometimes', 'nullable'] : ['nullable'];

        return [
            'vehicle_brand_id' => [...$optional, 'integer', 'exists:vehicle_brands,id'],
            'vehicle_model_id' => [...$optional, 'integer', 'exists:vehicle_models,id'],
            'vehicle_version_id' => [...$optional, 'integer', 'exists:vehicle_versions,id'],
            'year' => [...$optional, 'integer', 'min:1900', 'max:'.(now()->year + 2)],
            'plate' => [...$optional, 'string', 'max:30'],
            'vin' => [...$optional, 'string', 'max:80'],
            'color' => [...$optional, 'string', 'max:80'],
            'nickname' => [...$optional, 'string', 'max:120'],
            'notes' => [...$optional, 'string', 'max:1200'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
