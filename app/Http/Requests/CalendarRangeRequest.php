<?php

namespace App\Http\Requests;

use App\Models\Appointment;
use App\Models\Task;
use App\Services\CalendarService;
use App\Support\Business\BusinessContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CalendarRangeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after:from'],
            'types' => ['sometimes', 'array', 'max:5'],
            'types.*' => [Rule::in(CalendarService::types())],
            'statuses' => ['sometimes', 'array'],
            'statuses.*' => [Rule::in(array_values(array_unique([...Appointment::statuses(), ...Task::statuses()])))],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['from', 'to'])) {
                return;
            }
            $from = CarbonImmutable::parse($this->input('from'), BusinessContext::TIMEZONE);
            $to = CarbonImmutable::parse($this->input('to'), BusinessContext::TIMEZONE);
            if ($from->diffInSeconds($to) > CalendarService::MAX_RANGE_DAYS * 86400) {
                $validator->errors()->add('to', 'El rango del calendario no puede superar 31 días.');
            }
        }];
    }
}
