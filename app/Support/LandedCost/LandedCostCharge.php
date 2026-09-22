<?php

namespace App\Support\LandedCost;

final readonly class LandedCostCharge
{
    public const TYPE_DUTY = 'duty';

    public const TYPE_TAX = 'tax';

    public const TYPE_FEE = 'fee';

    public function __construct(
        public string $type,
        public string $code,
        public string $label,
        public int $amount,
        public string $currency,
    ) {}
}
