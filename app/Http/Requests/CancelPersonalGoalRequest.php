<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CancelPersonalGoalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['cancellation_reason' => ['nullable', 'string', 'max:255']];
    }
}
