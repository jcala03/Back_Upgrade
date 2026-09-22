<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminCommissionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $commission = $this->resource;
        $employee = $commission->relationLoaded('employee') ? $commission->employee : null;
        $branch = $commission->relationLoaded('branch') ? $commission->branch : null;
        $order = $commission->relationLoaded('order') ? $commission->order : null;

        return [
            'id' => $commission->id,
            'status' => $commission->status,
            'employee' => $employee ? [
                'id' => $employee->id,
                'name' => $employee->name,
            ] : null,
            'branch' => $branch ? [
                'id' => $branch->id,
                'code' => $branch->code,
                'name' => $branch->name,
            ] : null,
            'order' => $order ? [
                'id' => $order->id,
                'order_number' => $order->order_number,
            ] : null,
            'product' => [
                'id' => $commission->product_id,
                'name' => $commission->product_name_snapshot,
            ],
            'variant' => $commission->product_variant_id !== null
                || $commission->variant_name_snapshot !== null ? [
                    'id' => $commission->product_variant_id,
                    'name' => $commission->variant_name_snapshot,
                ] : null,
            'sku_snapshot' => $commission->sku_snapshot,
            'quantity' => $commission->quantity,
            'unit_commission' => $commission->unit_commission,
            'amount' => $commission->amount,
            'earned_at' => $commission->earned_at?->toIso8601String(),
            'voided_at' => $commission->voided_at?->toIso8601String(),
            'void_reason' => $commission->void_reason,
            'created_at' => $commission->created_at?->toIso8601String(),
        ];
    }
}
