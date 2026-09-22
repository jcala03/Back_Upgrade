<?php

namespace App\Support\LandedCost;

final readonly class LandedCostResult
{
    /** @param list<LandedCostCharge> $charges */
    public function __construct(
        public string $provider,
        public array $charges,
        public int $totalLandedCost,
        public string $currency,
        public string $quoteReference,
        public bool $estimated,
        public array $metadata = [],
        public ?string $shippingQuoteReference = null,
    ) {}

    public function forShippingQuote(string $quoteReference, array $metadata): self
    {
        return new self(
            provider: $this->provider,
            charges: $this->charges,
            totalLandedCost: $this->totalLandedCost,
            currency: $this->currency,
            quoteReference: $this->quoteReference,
            estimated: $this->estimated,
            metadata: $metadata,
            shippingQuoteReference: $quoteReference,
        );
    }
}
