<?php

namespace App\Http\Requests;

class UpdateServiceRequest extends StoreServiceRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('services.update');
    }

    public function rules(): array
    {
        return self::serviceRules(true);
    }
}
