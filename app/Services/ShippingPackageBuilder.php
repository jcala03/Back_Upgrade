<?php

namespace App\Services;

use App\Exceptions\EcommerceShippingException;
use App\Models\Order;
use App\Support\Shipping\ShippingAddressData;
use App\Support\Shipping\ShippingOriginData;
use App\Support\Shipping\ShippingPackageData;

class ShippingPackageBuilder
{
    public function __construct(
        private readonly LogisticsSnapshotResolver $logistics,
    ) {}

    /** @return list<ShippingPackageData> */
    public function build(Order $order, ShippingOriginData $origin, ShippingAddressData $destination): array
    {
        $order->loadMissing(['items.product', 'items.productVariant.product']);
        $packages = [];

        foreach ($order->items as $item) {
            $package = $this->logistics->resolve($item);
            if (! $package) {
                continue;
            }

            $missing = collect([
                'weight_grams' => $package->weightGrams,
                'length_mm' => $package->lengthMm,
                'width_mm' => $package->widthMm,
                'height_mm' => $package->heightMm,
            ])->filter(fn (int $value) => $value <= 0)->keys()->all();

            if ($origin->countryCode !== $destination->countryCode) {
                foreach (['countryOfOrigin', 'hsCode', 'customsDescription'] as $field) {
                    if (blank($package->{$field})) {
                        $missing[] = match ($field) {
                            'countryOfOrigin' => 'country_of_origin',
                            'hsCode' => 'hs_code',
                            default => 'customs_description',
                        };
                    }
                }
            }

            if ($missing !== []) {
                throw new EcommerceShippingException(
                    EcommerceShippingException::MISSING_LOGISTICS_DATA,
                    'Faltan datos logísticos obligatorios para cotizar el envío.',
                    ['order_item_id' => $package->orderItemId, 'missing_fields' => $missing],
                );
            }

            $packages[] = $package;
        }

        return $packages;
    }
}
