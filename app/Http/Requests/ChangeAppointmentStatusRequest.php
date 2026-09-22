<?php

namespace App\Http\Requests;

use App\Models\Appointment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeAppointmentStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('appointments.update');
    }

    public function rules(): array
    {
        return ['status' => ['required', Rule::in([Appointment::STATUS_CONFIRMED, Appointment::STATUS_IN_PROGRESS, Appointment::STATUS_COMPLETED, Appointment::STATUS_NO_SHOW])], 'availability_override' => ['sometimes', 'boolean'], 'availability_override_reason' => ['nullable', 'required_if:availability_override,true', 'string', 'max:255']];
    }
}
