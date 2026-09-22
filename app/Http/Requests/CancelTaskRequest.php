<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CancelTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('tasks.cancel');
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:255']];
    }
}
