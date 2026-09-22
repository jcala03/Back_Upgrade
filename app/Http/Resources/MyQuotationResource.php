<?php

namespace App\Http\Resources;

use App\Models\QuotationItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MyQuotationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $quotation = $this->resource;
        $branch = $quotation->relationLoaded('branch') ? $quotation->branch : null;
        $seller = $quotation->relationLoaded('salesEmployee') ? $quotation->salesEmployee : null;

        $data = [
            'id' => $quotation->id,
            'quotation_number' => $quotation->quotation_number,
            'status' => $quotation->status,
            'valid_until' => $quotation->valid_until?->toDateString(),
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
                'id' => $quotation->customer_id,
                'name' => $quotation->customer_name,
                'email' => $quotation->customer_email,
                'phone' => $quotation->customer_phone,
                'city' => $quotation->customer_city,
            ],
            'vehicle' => [
                'customer_vehicle_id' => $quotation->customer_vehicle_id,
                'brand_id' => $quotation->vehicle_brand_id,
                'brand_name' => $quotation->vehicle_brand_name,
                'model_id' => $quotation->vehicle_model_id,
                'model_name' => $quotation->vehicle_model_name,
                'version_id' => $quotation->vehicle_version_id,
                'version_name' => $quotation->vehicle_version_name,
                'year' => $quotation->vehicle_year,
                'plate' => $quotation->vehicle_plate,
                'color' => $quotation->vehicle_color,
            ],
            'subtotal' => $quotation->subtotal,
            'discount_total' => $quotation->discount_total,
            'total' => $quotation->total,
            'notes' => $quotation->notes,
            'items_count' => isset($quotation->items_count)
                ? (int) $quotation->items_count
                : ($quotation->relationLoaded('items') ? $quotation->items->count() : null),
            'order' => $quotation->relationLoaded('order') && $quotation->order ? [
                'id' => $quotation->order->id,
                'order_number' => $quotation->order->order_number,
                'status' => $quotation->order->status,
            ] : null,
            'converted_at' => $quotation->converted_at?->toIso8601String(),
            'created_at' => $quotation->created_at?->toIso8601String(),
            'updated_at' => $quotation->updated_at?->toIso8601String(),
        ];

        if ($quotation->relationLoaded('items')) {
            $data['items'] = $quotation->items
                ->map(fn (QuotationItem $item) => [
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

        return $data;
    }
}
