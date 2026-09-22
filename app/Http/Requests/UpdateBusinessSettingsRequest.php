<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBusinessSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('settings.update');
    }

    public function rules(): array
    {
        return [
            'business_name' => ['required', 'string', 'max:160'],
            'legal_name' => ['nullable', 'string', 'max:200'],
            'tax_id' => ['nullable', 'string', 'max:80'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:160'],
            'whatsapp' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:220'],
            'city' => ['nullable', 'string', 'max:120'],
            'quotation_validity_days' => ['required', 'integer', 'min:1', 'max:365'],
            'order_notification_email' => ['nullable', 'email', 'max:160'],
            'currency' => ['prohibited'],
            'timezone' => ['prohibited'],
        ];
    }
}
