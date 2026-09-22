<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCustomerVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('customers.update');
    }

    public function rules(): array
    {
        return StoreCustomerVehicleRequest::vehicleRules(true);
    }
}
