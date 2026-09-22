<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePersonalGoalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [...StoreAssignedGoalRequest::definitionRules(true), 'employee_id' => ['prohibited'], ...StoreAssignedGoalRequest::protectedRules()];
    }
}
