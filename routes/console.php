<?php

use App\Support\Business\BusinessContext;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('crm:notify-expiring-quotations')
    ->dailyAt('08:00')
    ->timezone(BusinessContext::TIMEZONE);

Schedule::command('ecommerce:expire-stock-reservations')
    ->everyMinute()
    ->withoutOverlapping();
