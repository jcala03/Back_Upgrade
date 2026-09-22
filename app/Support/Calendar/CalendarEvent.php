<?php

namespace App\Support\Calendar;

use Carbon\CarbonImmutable;

final readonly class CalendarEvent
{
    public function __construct(
        public string $id,
        public string $type,
        public int $sourceId,
        public string $title,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public bool $allDay,
        public ?string $status,
        public ?array $employee,
        public array $meta = [],
        public ?array $branch = null,
    ) {}

    public function toArray(string $timezone): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'source_id' => $this->sourceId,
            'title' => $this->title,
            'starts_at' => $this->startsAt->setTimezone($timezone)->toIso8601String(),
            'ends_at' => $this->endsAt->setTimezone($timezone)->toIso8601String(),
            'all_day' => $this->allDay,
            'status' => $this->status,
            'employee' => $this->employee,
            'branch' => $this->branch,
            'meta' => $this->meta,
        ];
    }
}
