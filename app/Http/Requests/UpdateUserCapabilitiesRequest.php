<?php

namespace App\Http\Requests;

use App\Models\UserCapability;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateUserCapabilitiesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('users.update');
    }

    public function rules(): array
    {
        return [
            'capabilities' => [
                'present',
                'array',
                'max:'.count(UserCapability::allowed()),
            ],
            'capabilities.*' => [
                'string',
                'distinct:strict',
                Rule::in(UserCapability::allowed()),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $capabilities = $this->input('capabilities');

            if (is_array($capabilities) && ! array_is_list($capabilities)) {
                $validator->errors()->add(
                    'capabilities',
                    'Las capabilities deben enviarse como una lista JSON.',
                );
            }
        });
    }
}
