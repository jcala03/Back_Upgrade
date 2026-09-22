<?php

namespace App\Providers;

use App\Contracts\LandedCostProvider;
use App\Contracts\ShippingQuoteProvider;
use App\Services\DhlLandedCostProvider;
use App\Services\EnviaShippingQuoteProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ShippingQuoteProvider::class, EnviaShippingQuoteProvider::class);
        $this->app->bind(LandedCostProvider::class, DhlLandedCostProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $email = Str::lower(trim((string) $request->input('email')));

            return Limit::perMinute(5)->by($email.'|'.$request->ip());
        });
    }
}
