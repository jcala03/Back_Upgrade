<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePersonalGoalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [...StoreAssignedGoalRequest::definitionRules(), 'employee_id' => ['prohibited'], ...StoreAssignedGoalRequest::protectedRules()];
    }
}
