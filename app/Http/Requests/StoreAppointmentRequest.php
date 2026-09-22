<?php

namespace App\Http\Requests;

use App\Models\Appointment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('appointments.create');
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'customer_vehicle_id' => ['nullable', 'integer', 'exists:customer_vehicles,id'],
            'service_id' => ['nullable', 'integer', 'exists:services,id'],
            'responsible_employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'status' => ['sometimes', Rule::in(Appointment::initialStatuses())],
            'title' => ['required', 'string', 'max:180'], 'description' => ['nullable', 'string'],
            'contact_name' => ['nullable', 'required_without:customer_id', 'string', 'max:160'],
            'contact_phone' => ['nullable', 'required_without:customer_id', 'string', 'max:40'],
            'contact_email' => ['nullable', 'email', 'max:160'], 'vehicle_description' => ['nullable', 'string', 'max:255'],
            'starts_at' => ['required', 'date'], 'ends_at' => ['required', 'date', 'after:starts_at'],
            'availability_override' => ['sometimes', 'boolean'],
            'availability_override_reason' => ['nullable', 'required_if:availability_override,true', 'string', 'max:255'],
            'branch_id' => ['prohibited'], 'source' => ['prohibited'], 'service_name' => ['prohibited'], 'created_by' => ['prohibited'], 'updated_by' => ['prohibited'],
            'cancelled_by' => ['prohibited'], 'cancelled_at' => ['prohibited'], 'cancellation_reason' => ['prohibited'],
            'availability_overridden_by' => ['prohibited'], 'availability_overridden_at' => ['prohibited'],
        ];
    }
}
