<?php

namespace App\Support\Reports;

use App\Support\Business\BusinessContext;
use Carbon\CarbonImmutable;

final class ReportPeriod
{
    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $toExclusive,
        public readonly string $dateFrom,
        public readonly string $dateTo,
    ) {}

    public static function from(array $filters): self
    {
        $today = BusinessContext::today();
        $from = isset($filters['date_from'])
            ? CarbonImmutable::parse($filters['date_from'], BusinessContext::TIMEZONE)->startOfDay()
            : $today->subDays(29);
        $to = isset($filters['date_to'])
            ? CarbonImmutable::parse($filters['date_to'], BusinessContext::TIMEZONE)->startOfDay()
            : $today;

        return new self($from->utc(), $to->addDay()->utc(), $from->toDateString(), $to->toDateString());
    }

    public function response(): array
    {
        return ['timezone' => BusinessContext::TIMEZONE, 'date_from' => $this->dateFrom, 'date_to' => $this->dateTo];
    }

    public function days(): array
    {
        $from = CarbonImmutable::parse($this->dateFrom, BusinessContext::TIMEZONE);
        $to = CarbonImmutable::parse($this->dateTo, BusinessContext::TIMEZONE);
        $days = [];
        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            $days[] = $day->toDateString();
        }

        return $days;
    }
}
