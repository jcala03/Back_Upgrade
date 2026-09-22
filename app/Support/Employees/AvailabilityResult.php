<?php

namespace App\Support\Employees;

final readonly class AvailabilityResult
{
    public function __construct(
        public bool $available,
        public array $reasonCodes = [],
    ) {}

    public static function available(): self
    {
        return new self(true);
    }

    public static function unavailable(string ...$reasonCodes): self
    {
        return new self(false, array_values(array_unique($reasonCodes)));
    }

    public function toArray(): array
    {
        return ['available' => $this->available, 'reason_codes' => $this->reasonCodes];
    }
}
