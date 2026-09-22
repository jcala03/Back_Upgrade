<?php

namespace App\Http\Requests;

use App\Models\Task;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('tasks.create');
    }

    public function rules(): array
    {
        return self::taskRules();
    }

    public static function taskRules(bool $partial = false): array
    {
        $presence = $partial ? 'sometimes' : 'required';

        return [
            'assigned_employee_id' => [$presence, 'integer', 'exists:employees,id'],
            'title' => [$presence, 'string', 'max:180'],
            'description' => ['nullable', 'string'],
            'priority' => ['sometimes', Rule::in(Task::priorities())],
            'due_at' => ['nullable', 'date'],
            'scheduled_starts_at' => ['nullable', 'date', 'required_with:scheduled_ends_at'],
            'scheduled_ends_at' => ['nullable', 'date', 'required_with:scheduled_starts_at', 'after:scheduled_starts_at'],
            'availability_override' => ['sometimes', 'boolean'],
            'availability_override_reason' => ['nullable', 'string', 'max:255'],
            'branch_id' => ['prohibited'],
            'status' => ['prohibited'], 'created_by' => ['prohibited'], 'updated_by' => ['prohibited'],
            'started_at' => ['prohibited'], 'completed_at' => ['prohibited'], 'cancelled_at' => ['prohibited'],
            'cancelled_by' => ['prohibited'], 'cancellation_reason' => ['prohibited'],
        ];
    }
}
