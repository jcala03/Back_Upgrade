<?php

namespace App\Support\Shipping;

use Carbon\CarbonImmutable;

final readonly class ShippingQuoteResult
{
    public function __construct(
        public string $provider,
        public string $serviceCode,
        public string $serviceName,
        public int $amount,
        public string $currency,
        public string $quoteReference,
        public ?int $estimatedDaysMin = null,
        public ?int $estimatedDaysMax = null,
        public ?CarbonImmutable $expiresAt = null,
        public array $metadata = [],
        public ?int $branchId = null,
        public ?string $carrier = null,
    ) {}

    public function forBranch(int $branchId, array $sanitizedMetadata): self
    {
        return new self(
            provider: $this->provider,
            serviceCode: $this->serviceCode,
            serviceName: $this->serviceName,
            amount: $this->amount,
            currency: $this->currency,
            quoteReference: $this->quoteReference,
            estimatedDaysMin: $this->estimatedDaysMin,
            estimatedDaysMax: $this->estimatedDaysMax,
            expiresAt: $this->expiresAt,
            metadata: $sanitizedMetadata,
            branchId: $branchId,
            carrier: $this->carrier,
        );
    }

    public function isExpired(): bool
    {
        return $this->expiresAt?->isPast() ?? false;
    }
}
