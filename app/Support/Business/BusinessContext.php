<?php

namespace App\Support\Business;

use Carbon\CarbonImmutable;

final class BusinessContext
{
    public const TIMEZONE = 'America/Bogota';

    public const CURRENCY = 'COP';

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::TIMEZONE);
    }

    public static function today(): CarbonImmutable
    {
        return self::now()->startOfDay();
    }
}
