<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminCalendarRequest;
use App\Services\CalendarService;
use App\Support\Business\BusinessContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

class CalendarController extends Controller
{
    public function __construct(private readonly CalendarService $service) {}

    public function index(AdminCalendarRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $events = $this->service->events($filters);

        return response()->json(['data' => $events, 'meta' => $this->meta($filters, count($events))]);
    }

    private function meta(array $filters, int $count): array
    {
        return ['from' => CarbonImmutable::parse($filters['from'], BusinessContext::TIMEZONE)->setTimezone(BusinessContext::TIMEZONE)->toIso8601String(),
            'to' => CarbonImmutable::parse($filters['to'], BusinessContext::TIMEZONE)->setTimezone(BusinessContext::TIMEZONE)->toIso8601String(),
            'timezone' => BusinessContext::TIMEZONE, 'event_count' => $count];
    }
}
