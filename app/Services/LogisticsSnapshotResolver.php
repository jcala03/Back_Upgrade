<?php

namespace App\Services;

use App\Models\OrderItem;
use App\Support\Shipping\ShippingPackageData;

class LogisticsSnapshotResolver
{
    public function resolve(OrderItem $item): ?ShippingPackageData
    {
        if (($item->item_type ?? OrderItem::ITEM_TYPE_PRODUCT) !== OrderItem::ITEM_TYPE_PRODUCT) {
            return null;
        }

        $item->loadMissing(['product', 'productVariant.product']);
        if (! $item->product?->requires_shipping) {
            return null;
        }

        $logistics = $item->productVariant
            ? $item->productVariant->resolvedLogistics()
            : [
                'weight_grams' => $item->product->weight_grams,
                'length_mm' => $item->product->length_mm,
                'width_mm' => $item->product->width_mm,
                'height_mm' => $item->product->height_mm,
                'country_of_origin' => $item->product->country_of_origin,
                'hs_code' => $item->product->hs_code,
                'customs_description' => $item->product->customs_description,
            ];

        return new ShippingPackageData(
            orderItemId: (int) $item->id,
            content: $item->variant_name
                ? $item->product_name.' / '.$item->variant_name
                : $item->product_name,
            quantity: (int) $item->quantity,
            weightGrams: (int) ($logistics['weight_grams'] ?? 0),
            lengthMm: (int) ($logistics['length_mm'] ?? 0),
            widthMm: (int) ($logistics['width_mm'] ?? 0),
            heightMm: (int) ($logistics['height_mm'] ?? 0),
            declaredUnitValue: (int) $item->unit_price,
            countryOfOrigin: $logistics['country_of_origin'] ?? null,
            hsCode: $logistics['hs_code'] ?? null,
            customsDescription: $logistics['customs_description'] ?? null,
        );
    }
}
