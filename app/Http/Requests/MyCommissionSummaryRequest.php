<?php

namespace App\Http\Requests;

use App\Models\UserCapability;
use Illuminate\Foundation\Http\FormRequest;

class MyCommissionSummaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission(UserCapability::COMMISSIONS_VIEW_OWN);
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['prohibited'],
            'branch_id' => ['prohibited'],
            'sales_employee_id' => ['prohibited'],
            'user_id' => ['prohibited'],
        ];
    }
}
