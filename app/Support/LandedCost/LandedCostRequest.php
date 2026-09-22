<?php

namespace App\Support\LandedCost;

use App\Support\Shipping\ShippingAddressData;
use App\Support\Shipping\ShippingOriginData;

final readonly class LandedCostRequest
{
    /** @param list<LandedCostItemData> $items */
    public function __construct(
        public ShippingOriginData $origin,
        public ShippingAddressData $destination,
        public array $items,
        public int $shippingAmount,
        public int $insuranceAmount,
        public string $currency,
        public ?string $carrier = null,
        public ?string $serviceCode = null,
    ) {}
}
