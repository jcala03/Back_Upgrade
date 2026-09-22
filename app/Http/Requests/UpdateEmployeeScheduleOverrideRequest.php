<?php

namespace App\Http\Requests;

class UpdateEmployeeScheduleOverrideRequest extends StoreEmployeeScheduleOverrideRequest
{
    public function rules(): array
    {
        return self::overrideRules(true);
    }
}
