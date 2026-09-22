<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('employees.create');
    }

    public function rules(): array
    {
        return self::employeeRules();
    }

    public static function employeeRules(
        bool $partial = false,
        ?int $ignoreEmployeeId = null,
        bool $activeBranchOnly = true,
    ): array {
        $presence = $partial ? 'sometimes' : 'required';
        $branchExists = Rule::exists('branches', 'id');
        if ($activeBranchOnly) {
            $branchExists->where(fn ($query) => $query->where('is_active', true));
        }

        return [
            'user_id' => [
                'nullable', 'integer', 'exists:users,id',
                Rule::unique('employees', 'user_id')->ignore($ignoreEmployeeId),
            ],
            'branch_id' => [
                'nullable', 'integer', $branchExists,
            ],
            'name' => [$presence, 'string', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'],
            'job_title' => [$presence, 'string', 'max:120'],
            'specialty' => ['nullable', 'string', 'max:160'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'hire_date' => ['nullable', 'date'],
        ];
    }
}
