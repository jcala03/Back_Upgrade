<?php

namespace App\Http\Requests;

use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrderStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permission = $this->input('status') === Order::STATUS_CANCELLED
            ? 'orders.cancel'
            : 'orders.update';

        return (bool) $this->user()?->hasPermission($permission);
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(Order::statuses())],
            'reason' => ['nullable', 'string', 'max:1200'],
            'branch_id' => [
                Rule::prohibitedIf(fn () => $this->input('status') !== Order::STATUS_CONFIRMED),
                'nullable',
                'integer',
                Rule::exists('branches', 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
            'sales_employee_id' => [
                Rule::prohibitedIf(fn () => $this->input('status') !== Order::STATUS_CONFIRMED),
                'nullable',
                'integer',
                Rule::exists('employees', 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
        ];
    }
}
