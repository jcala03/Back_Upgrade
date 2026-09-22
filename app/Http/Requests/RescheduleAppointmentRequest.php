<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RescheduleAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('appointments.update');
    }

    public function rules(): array
    {
        return ['starts_at' => ['required', 'date'], 'ends_at' => ['required', 'date', 'after:starts_at'], 'availability_override' => ['sometimes', 'boolean'], 'availability_override_reason' => ['nullable', 'required_if:availability_override,true', 'string', 'max:255']];
    }
}
