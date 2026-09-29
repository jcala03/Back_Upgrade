<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePublicOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function validationData(): array
    {
        return [
            ...parent::validationData(),
            'idempotency_key' => $this->header('Idempotency-Key'),
        ];
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'max:255', 'regex:/^[\x21-\x7E]+$/'],
            'customer_name' => ['required', 'string', 'min:3', 'max:120'],
            'customer_email' => ['required', 'email', 'max:160'],
            'customer_phone' => ['required', 'string', 'min:7', 'max:40'],
            'customer_city' => ['nullable', 'string', 'max:120'],
            'customer_address' => ['nullable', 'string', 'max:220'],
            'customer_notes' => ['nullable', 'string', 'max:1200'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.product_variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
            'items.*.unit_price' => ['prohibited'], 'items.*.discount_amount' => ['prohibited'],
            'items.*.item_type' => ['prohibited'], 'items.*.service_id' => ['prohibited'],
            'subtotal' => ['prohibited'],
            'discount_total' => ['prohibited'],
            'charges_total' => ['prohibited'],
            'total' => ['prohibited'],
            'currency' => ['prohibited'],
            'branch_id' => ['prohibited'],
            'fulfillment_type' => ['prohibited'],
            'charges' => ['prohibited'],
            'order_charges' => ['prohibited'],
            'shipping_total' => ['prohibited'],
            'insurance_total' => ['prohibited'],
            'tax_total' => ['prohibited'],
            'duties_total' => ['prohibited'],
        ];
    }
}
