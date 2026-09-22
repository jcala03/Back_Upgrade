<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAssignedGoalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('goals.create');
    }

    public function rules(): array
    {
        return ['employee_id' => ['required', 'integer', 'exists:employees,id'], ...self::definitionRules(), ...self::protectedRules()];
    }

    public static function definitionRules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return ['title' => [$required, 'string', 'max:180'], 'description' => ['nullable', 'string'],
            'target_value' => ['nullable', 'numeric', 'gt:0'], 'unit' => ['nullable', 'string', 'max:40'],
            'starts_on' => ['nullable', 'date'], 'due_on' => ['nullable', 'date', 'after_or_equal:starts_on']];
    }

    public static function protectedRules(): array
    {
        return ['source' => ['prohibited'], 'metric_type' => ['prohibited'], 'current_value' => ['prohibited'], 'status' => ['prohibited'],
            'created_by' => ['prohibited'], 'updated_by' => ['prohibited'], 'completed_by' => ['prohibited'], 'completed_at' => ['prohibited'],
            'cancelled_by' => ['prohibited'], 'cancelled_at' => ['prohibited'], 'cancellation_reason' => ['prohibited']];
    }
}
