<?php

namespace App\Http\Requests;

class StoreMySaleRequest extends StoreAdminOrderRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('orders.create_own');
    }

    public function rules(): array
    {
        $rules = parent::rules();
        $prohibited = ['prohibited'];

        foreach ([
            'status',
            'branch_id',
            'sales_employee_id',
            'user_id',
            'payment',
            'origin',
            'quotation_id',
            'order_number',
            'created_by',
            'cancelled_by',
            'confirmed_at',
            'completed_at',
            'cancelled_at',
            'stock_committed_at',
            'stock_reverted_at',
            'payment_status',
        ] as $field) {
            $rules[$field] = $prohibited;
        }

        return $rules;
    }
}
