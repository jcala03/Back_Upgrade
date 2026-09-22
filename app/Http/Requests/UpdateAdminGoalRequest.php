<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAdminGoalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('goals.update');
    }

    public function rules(): array
    {
        return ['employee_id' => ['sometimes', 'integer', 'exists:employees,id'], ...StoreAssignedGoalRequest::definitionRules(true), ...StoreAssignedGoalRequest::protectedRules()];
    }
}
