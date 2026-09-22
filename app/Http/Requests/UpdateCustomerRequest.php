<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('customers.update');
    }

    public function rules(): array
    {
        return StoreCustomerRequest::customerRules(true);
    }
}
