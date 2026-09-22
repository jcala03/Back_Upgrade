<?php

namespace App\Http\Requests;

class UpdateEmployeeScheduleRequest extends StoreEmployeeScheduleRequest
{
    public function rules(): array
    {
        return self::scheduleRules(true);
    }
}
