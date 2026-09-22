<?php

namespace App\Http\Requests;

class MyCalendarRequest extends CalendarRangeRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [...parent::rules(), 'employee_ids' => ['prohibited']];
    }
}
