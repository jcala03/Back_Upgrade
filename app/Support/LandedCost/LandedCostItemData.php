<?php

namespace App\Support\LandedCost;

final readonly class LandedCostItemData
{
    public function __construct(
        public int $orderItemId,
        public string $name,
        public string $customsDescription,
        public string $countryOfOrigin,
        public string $hsCode,
        public int $quantity,
        public int $declaredUnitValue,
        public int $weightGrams,
        public int $lengthMm,
        public int $widthMm,
        public int $heightMm,
    ) {}
}
