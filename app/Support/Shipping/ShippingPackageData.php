<?php

namespace App\Support\Shipping;

final readonly class ShippingPackageData
{
    public function __construct(
        public int $orderItemId,
        public string $content,
        public int $quantity,
        public int $weightGrams,
        public int $lengthMm,
        public int $widthMm,
        public int $heightMm,
        public int $declaredUnitValue,
        public ?string $countryOfOrigin,
        public ?string $hsCode,
        public ?string $customsDescription,
    ) {}
}
