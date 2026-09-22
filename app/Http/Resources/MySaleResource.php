<?php

namespace App\Http\Resources;

use App\Models\OrderItem;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MySaleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $order = $this->resource;
        $branch = $order->relationLoaded('branch') ? $order->branch : null;
        $seller = $order->relationLoaded('salesEmployee') ? $order->salesEmployee : null;

        $data = [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'origin' => $order->origin,
            'status' => $order->status,
            'payment_status' => $order->payment_status,
            'branch' => $branch ? [
                'id' => $branch->id,
                'code' => $branch->code,
                'name' => $branch->name,
                'city' => $branch->city,
                'is_active' => $branch->is_active,
            ] : null,
            'sales_employee' => $seller ? [
                'id' => $seller->id,
                'name' => $seller->name,
            ] : null,
            'customer' => [
                'id' => $order->customer_id,
                'name' => $order->customer_name,
                'email' => $order->customer_email,
                'phone' => $order->customer_phone,
                'city' => $order->customer_city,
            ],
            'vehicle' => [
                'customer_vehicle_id' => $order->customer_vehicle_id,
                'brand_id' => $order->vehicle_brand_id,
                'brand_name' => $order->vehicle_brand_name,
                'model_id' => $order->vehicle_model_id,
                'model_name' => $order->vehicle_model_name,
                'version_id' => $order->vehicle_version_id,
                'version_name' => $order->vehicle_version_name,
                'year' => $order->vehicle_year,
                'plate' => $order->vehicle_plate,
                'color' => $order->vehicle_color,
            ],
            'subtotal' => $order->subtotal,
            'discount_total' => $order->discount_total,
            'total' => $order->total,
            'items_count' => isset($order->items_count)
                ? (int) $order->items_count
                : ($order->relationLoaded('items') ? $order->items->count() : null),
            'payments_count' => isset($order->payments_count)
                ? (int) $order->payments_count
                : ($order->relationLoaded('payments') ? $order->payments->count() : null),
            'confirmed_at' => $order->confirmed_at?->toIso8601String(),
            'completed_at' => $order->completed_at?->toIso8601String(),
            'created_at' => $order->created_at?->toIso8601String(),
            'updated_at' => $order->updated_at?->toIso8601String(),
        ];

        if ($order->relationLoaded('items')) {
            $data['items'] = $order->items
                ->map(fn (OrderItem $item) => [
                    'id' => $item->id,
                    'item_type' => $item->item_type,
                    'product_id' => $item->product_id,
                    'product_variant_id' => $item->product_variant_id,
                    'service_id' => $item->service_id,
                    'product_name' => $item->product_name,
                    'product_slug' => $item->product_slug,
                    'product_sku' => $item->product_sku,
                    'variant_name' => $item->variant_name,
                    'variant_sku' => $item->variant_sku,
                    'variant_specs' => $item->variant_specs,
                    'service_name' => $item->service_name,
                    'service_description' => $item->service_description,
                    'unit_price' => $item->unit_price,
                    'quantity' => $item->quantity,
                    'subtotal' => $item->subtotal,
                    'discount_amount' => $item->discount_amount,
                    'total' => $item->total,
                ])
                ->values();
        }

        if ($order->relationLoaded('payments')) {
            $data['payments'] = $order->payments
                ->map(fn (Payment $payment) => self::payment($payment))
                ->values();
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public static function payment(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'amount' => $payment->amount,
            'method' => $payment->method,
            'status' => $payment->status,
            'provider' => $payment->provider,
            'reference' => $payment->reference,
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'created_at' => $payment->created_at?->toIso8601String(),
        ];
    }
}
