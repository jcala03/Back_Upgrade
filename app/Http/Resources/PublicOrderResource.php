<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicOrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $order = $this->resource;

        if ($request->is('api/orders')) {
            return [
                'id' => $order->id, 'order_number' => $order->order_number, 'status' => $order->status,
                'payment_status' => $order->payment_status, 'customer_name' => $order->customer_name,
                'customer_email' => $order->customer_email, 'customer_phone' => $order->customer_phone,
                'customer_city' => $order->customer_city, 'customer_address' => $order->customer_address,
                'subtotal' => $order->subtotal, 'discount_total' => $order->discount_total,
                'charges_total' => $order->charges_total, 'currency' => $order->currency, 'total' => $order->total,
                'items' => $order->relationLoaded('items') ? PublicOrderItemResource::collection($order->items)->resolve($request) : [],
                'created_at' => $order->created_at,
            ];
        }

        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'order_status' => $order->status,
            'stock_reservation_expires_at' => $order->stock_reservation_expires_at?->toIso8601String(),
            'can_retry_payment' => $order->canRetryPayment(),
            'payment_attempt_status' => $order->publicPaymentAttemptStatus(),
            'status' => $order->status,
            'payment_status' => $order->payment_status,
            'fulfillment_type' => $order->fulfillment_type,
            'customer_name' => $order->customer_name,
            'customer_email' => $order->customer_email,
            'customer_phone' => $order->customer_phone,
            'customer_city' => $order->customer_city,
            'customer_address' => $order->customer_address,
            'subtotal' => $order->subtotal,
            'discount_total' => $order->discount_total,
            'charges_total' => $order->charges_total,
            'currency' => $order->currency,
            'total' => $order->total,
            'items' => $order->relationLoaded('items')
                ? PublicOrderItemResource::collection($order->items)->resolve($request)
                : [],
            'charges' => $order->relationLoaded('charges') ? PublicOrderChargeResource::collection($order->charges)->resolve($request) : [],
            'shipping_address' => $order->relationLoaded('shippingAddress') && $order->shippingAddress ? [
                'recipient_name' => $order->shippingAddress->recipient_name, 'recipient_phone' => $order->shippingAddress->recipient_phone,
                'country_code' => $order->shippingAddress->country_code, 'state' => $order->shippingAddress->state, 'city' => $order->shippingAddress->city,
                'postal_code' => $order->shippingAddress->postal_code, 'address_line1' => $order->shippingAddress->address_line1, 'address_line2' => $order->shippingAddress->address_line2, 'delivery_notes' => $order->shippingAddress->delivery_notes,
            ] : null,
            'created_at' => $order->created_at,
        ];
    }
}
