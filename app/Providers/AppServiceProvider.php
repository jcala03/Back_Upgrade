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
    public const PUBLIC_ORDER_REQUESTS_PER_MINUTE = 10;

    public const CHECKOUT_MUTATION_REQUESTS_PER_MINUTE = 30;

    public const CHECKOUT_SHIPPING_REQUESTS_PER_MINUTE = 12;

    public const CHECKOUT_PAYMENT_REQUESTS_PER_MINUTE = 10;

    public const WOMPI_WEBHOOK_REQUESTS_PER_MINUTE = 600;

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

        RateLimiter::for('public-orders', fn (Request $request) => Limit::perMinute(self::PUBLIC_ORDER_REQUESTS_PER_MINUTE)
            ->by($request->ip()));

        $checkoutIdentity = fn (Request $request): string => hash('sha256', (string) $request->route('publicToken')).'|'.$request->ip();

        RateLimiter::for('checkout-mutations', fn (Request $request) => Limit::perMinute(self::CHECKOUT_MUTATION_REQUESTS_PER_MINUTE)
            ->by($checkoutIdentity($request)));

        RateLimiter::for('checkout-shipping', fn (Request $request) => Limit::perMinute(self::CHECKOUT_SHIPPING_REQUESTS_PER_MINUTE)
            ->by($checkoutIdentity($request)));

        RateLimiter::for('checkout-payment', fn (Request $request) => Limit::perMinute(self::CHECKOUT_PAYMENT_REQUESTS_PER_MINUTE)
            ->by($checkoutIdentity($request)));

        RateLimiter::for('wompi-webhook', fn (Request $request) => Limit::perMinute(self::WOMPI_WEBHOOK_REQUESTS_PER_MINUTE)
            ->by($request->ip()));
    }
}
