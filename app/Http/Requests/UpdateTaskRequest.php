<?php

namespace App\Http\Requests;

class UpdateTaskRequest extends StoreTaskRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('tasks.update');
    }

    public function rules(): array
    {
        return self::taskRules(true);
    }
}
