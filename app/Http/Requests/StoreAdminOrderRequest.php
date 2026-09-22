<?php

namespace App\Http\Requests;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreAdminOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('orders.create');
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::in([Order::STATUS_PENDING, Order::STATUS_CONFIRMED])],
            'branch_id' => [
                'required',
                'integer',
                Rule::exists('branches', 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
            'sales_employee_id' => [
                'nullable',
                'integer',
                Rule::exists('employees', 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'customer_vehicle_id' => ['nullable', 'integer', 'exists:customer_vehicles,id'],
            'customer_name' => ['nullable', 'string', 'max:120'], 'customer_email' => ['nullable', 'email', 'max:160'],
            'customer_phone' => ['nullable', 'string', 'max:40'], 'customer_document' => ['nullable', 'string', 'max:80'],
            'customer_city' => ['nullable', 'string', 'max:120'], 'customer_address' => ['nullable', 'string', 'max:220'],
            'customer_notes' => ['nullable', 'string', 'max:1200'],
            'items' => ['required', 'array', 'min:1'],
            'items.*' => ['required', 'array'],
            'items.*.item_type' => ['nullable', Rule::in(['product', 'service'])],
            'items.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'items.*.product_variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'items.*.service_id' => ['nullable', 'integer', 'exists:services,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:999'],
            'items.*.discount_amount' => ['nullable', 'integer', 'min:0'], 'items.*.unit_price' => ['prohibited'],
            'items.*.unit_cost' => ['prohibited'], 'items.*.subtotal' => ['prohibited'], 'items.*.total' => ['prohibited'],
            'items.*.service_name' => ['prohibited'], 'items.*.service_description' => ['prohibited'],
            'subtotal' => ['prohibited'], 'discount_total' => ['prohibited'], 'total' => ['prohibited'],
            'vehicle_brand_id' => ['nullable', 'integer', 'exists:vehicle_brands,id'],
            'vehicle_model_id' => ['nullable', 'integer', 'exists:vehicle_models,id'],
            'vehicle_version_id' => ['nullable', 'integer', 'exists:vehicle_versions,id'],
            'vehicle_year' => ['nullable', 'integer', 'min:1900', 'max:'.(now()->year + 2)],
            'vehicle_plate' => ['nullable', 'string', 'max:30'], 'vehicle_vin' => ['nullable', 'string', 'max:80'],
            'vehicle_color' => ['nullable', 'string', 'max:80'], 'vehicle_notes' => ['nullable', 'string', 'max:1200'],
            'payment' => ['nullable', 'array'], 'payment.amount' => ['required_with:payment', 'integer', 'min:1'],
            'payment.method' => ['required_with:payment', Rule::in(Payment::methods())],
            'payment.status' => ['nullable', Rule::in(Payment::statuses())], 'payment.provider' => ['nullable', 'string', 'max:120'],
            'payment.reference' => ['nullable', 'string', 'max:160'], 'payment.transaction_id' => ['nullable', 'string', 'max:160'],
            'payment.notes' => ['nullable', 'string', 'max:1200'], 'payment.paid_at' => ['nullable', 'date'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach ((array) $this->input('items', []) as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }

                $type = $item['item_type'] ?? 'product';
                if ($type === 'service') {
                    if (empty($item['service_id'])) {
                        $validator->errors()->add("items.{$index}.service_id", 'Debes indicar el servicio.');
                    }
                    if (! empty($item['product_id']) || ! empty($item['product_variant_id'])) {
                        $validator->errors()->add("items.{$index}.product_id", 'Una línea de servicio no puede incluir producto ni variante.');
                    }
                } elseif ($type === 'product') {
                    if (empty($item['product_id'])) {
                        $validator->errors()->add("items.{$index}.product_id", 'Debes indicar el producto.');
                    }
                    if (! empty($item['service_id'])) {
                        $validator->errors()->add("items.{$index}.service_id", 'Una línea de producto no puede incluir servicio.');
                    }
                }
            }
        });
    }
}
