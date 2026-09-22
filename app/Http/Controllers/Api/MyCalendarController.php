<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MyCalendarRequest;
use App\Services\CalendarService;
use App\Support\Business\BusinessContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

class MyCalendarController extends Controller
{
    public function __construct(private readonly CalendarService $service) {}

    public function index(MyCalendarRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $employee = $request->user()->employee;
        $events = $employee ? $this->service->events($filters, $employee->id) : [];

        return response()->json(['data' => $events, 'meta' => [
            'from' => CarbonImmutable::parse($filters['from'], BusinessContext::TIMEZONE)->setTimezone(BusinessContext::TIMEZONE)->toIso8601String(),
            'to' => CarbonImmutable::parse($filters['to'], BusinessContext::TIMEZONE)->setTimezone(BusinessContext::TIMEZONE)->toIso8601String(),
            'timezone' => BusinessContext::TIMEZONE, 'event_count' => count($events), 'employee_linked' => $employee !== null,
        ]]);
    }
}
