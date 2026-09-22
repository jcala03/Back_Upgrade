<?php

namespace App\Support\Shipping;

final readonly class ShippingAddressData
{
    public function __construct(
        public string $recipientName,
        public string $recipientPhone,
        public string $countryCode,
        public string $state,
        public string $city,
        public string $postalCode,
        public string $addressLine1,
        public ?string $addressLine2 = null,
        public ?string $deliveryNotes = null,
    ) {}
}
