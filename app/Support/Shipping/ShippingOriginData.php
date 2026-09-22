<?php

namespace App\Support\Shipping;

final readonly class ShippingOriginData
{
    public function __construct(
        public int $branchId,
        public string $branchCode,
        public string $countryCode,
        public string $state,
        public string $city,
        public string $postalCode,
        public string $addressLine1,
        public ?string $addressLine2 = null,
    ) {}
}
