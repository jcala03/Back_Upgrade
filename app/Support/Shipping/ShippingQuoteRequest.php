<?php

namespace App\Support\Shipping;

final readonly class ShippingQuoteRequest
{
    /** @param list<ShippingPackageData> $packages */
    public function __construct(
        public ShippingOriginData $origin,
        public ShippingAddressData $destination,
        public array $packages,
        public string $currency,
    ) {}
}
