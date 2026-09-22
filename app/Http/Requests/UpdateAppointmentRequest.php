<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('appointments.update');
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['sometimes', 'nullable', 'integer', 'exists:customers,id'],
            'customer_vehicle_id' => ['sometimes', 'nullable', 'integer', 'exists:customer_vehicles,id'],
            'service_id' => ['sometimes', 'nullable', 'integer', 'exists:services,id'],
            'responsible_employee_id' => ['sometimes', 'nullable', 'integer', 'exists:employees,id'],
            'title' => ['sometimes', 'string', 'max:180'], 'description' => ['sometimes', 'nullable', 'string'],
            'contact_name' => ['sometimes', 'string', 'max:160'], 'contact_phone' => ['sometimes', 'string', 'max:40'],
            'contact_email' => ['sometimes', 'nullable', 'email', 'max:160'], 'vehicle_description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'branch_id' => ['prohibited'], 'starts_at' => ['prohibited'], 'ends_at' => ['prohibited'], 'status' => ['prohibited'], 'source' => ['prohibited'],
            'service_name' => ['prohibited'], 'created_by' => ['prohibited'], 'updated_by' => ['prohibited'],
            'cancelled_by' => ['prohibited'], 'cancelled_at' => ['prohibited'], 'cancellation_reason' => ['prohibited'],
            'availability_override' => ['sometimes', 'boolean'], 'availability_override_reason' => ['nullable', 'string', 'max:255'],
            'availability_overridden_by' => ['prohibited'], 'availability_overridden_at' => ['prohibited'],
        ];
    }
}
