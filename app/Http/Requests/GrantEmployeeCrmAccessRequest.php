<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class GrantEmployeeCrmAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('employees.update')
            && (bool) $this->user()?->hasPermission('users.create');
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:160', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'role' => ['prohibited'],
            'permissions' => ['prohibited'],
            'is_admin' => ['prohibited'],
            'is_active' => ['prohibited'],
            'name' => ['prohibited'],
            'job_title' => ['prohibited'],
            'specialty' => ['prohibited'],
        ];
    }
}
