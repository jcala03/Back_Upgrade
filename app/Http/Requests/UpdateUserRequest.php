<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('users.update');
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'email' => ['sometimes', 'required', 'email', 'max:160', Rule::unique('users', 'email')->ignore($this->route('user'))],
            'is_active' => ['sometimes', 'boolean'],
            'role' => ['sometimes', Rule::in(User::roles())],
            'password' => ['prohibited'],
            'password_confirmation' => ['prohibited'],
        ];
    }
}
