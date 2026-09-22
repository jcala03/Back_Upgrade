<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DashboardCompareRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('dashboard.view');
    }

    public function rules(): array
    {
        return [
            'branch_ids' => ['required', 'array', 'min:2', 'max:25'],
            'branch_ids.*' => ['required', 'integer', 'distinct', 'exists:branches,id'],
        ];
    }
}
