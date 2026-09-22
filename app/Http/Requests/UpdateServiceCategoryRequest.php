<?php

namespace App\Http\Requests;

class UpdateServiceCategoryRequest extends StoreServiceCategoryRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('services.update');
    }

    public function rules(): array
    {
        return self::categoryRules(true);
    }
}
