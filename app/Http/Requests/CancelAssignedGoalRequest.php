<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CancelAssignedGoalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('goals.cancel');
    }

    public function rules(): array
    {
        return ['cancellation_reason' => ['required', 'string', 'max:255']];
    }
}
