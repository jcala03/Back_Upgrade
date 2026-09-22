<?php

namespace App\Services;

use App\Exceptions\EcommercePaymentException;
use App\Models\Payment;
use App\Support\Payments\CopAmount;

class WompiCheckoutService
{
    public function __construct(private readonly WompiIntegritySignatureService $signature) {}

    public function reservationMinutes(): int
    {
        $minutes = config('services.wompi.reservation_minutes');
        if (! is_int($minutes) || $minutes < 1 || $minutes > 1440) {
            throw new EcommercePaymentException(EcommercePaymentException::WOMPI_NOT_CONFIGURED);
        }

        return $minutes;
    }

    public function assertConfigured(): void
    {
        $base = rtrim((string) config('services.wompi.base_url'), '/');
        $environment = match ($base) {
            'https://sandbox.wompi.co/v1' => 'test',
            'https://production.wompi.co/v1' => 'prod',
            default => null,
        };
        $publicKey = (string) config('services.wompi.public_key');
        $secret = (string) config('services.wompi.integrity_secret');
        $redirect = (string) config('services.wompi.redirect_url');
        if ($environment === null
            || ! str_starts_with($publicKey, 'pub_'.$environment.'_')
            || ! str_starts_with($secret, $environment.'_integrity_')
            || strlen($publicKey) <= strlen('pub_'.$environment.'_')
            || strlen($secret) <= strlen($environment.'_integrity_')
            || rtrim((string) config('services.wompi.checkout_url'), '/') !== 'https://checkout.wompi.co/p'
            || ! filter_var($redirect, FILTER_VALIDATE_URL)
            || ! in_array(parse_url($redirect, PHP_URL_SCHEME), ['https', 'http'], true)
            || parse_url($redirect, PHP_URL_USER) !== null
            || parse_url($redirect, PHP_URL_FRAGMENT) !== null
        ) {
            throw new EcommercePaymentException(EcommercePaymentException::WOMPI_NOT_CONFIGURED);
        }
        $this->reservationMinutes();
    }

    /** Only public checkout parameters are returned or persisted. */
    public function payload(Payment $payment): array
    {
        $this->assertConfigured();
        $amount = CopAmount::toCents($payment->amount, $payment->currency);
        $expiration = $payment->expires_at->copy()->utc()->format('Y-m-d\TH:i:s.000\Z');

        return [
            'provider' => Payment::PROVIDER_WOMPI,
            'checkout_url' => config('services.wompi.checkout_url'),
            'public_key' => config('services.wompi.public_key'),
            'amount_in_cents' => $amount,
            'currency' => $payment->currency,
            'reference' => $payment->reference,
            'integrity_signature' => $this->signature->sign(
                $payment->reference, $amount, $payment->currency,
                config('services.wompi.integrity_secret'), $expiration,
            ),
            'redirect_url' => config('services.wompi.redirect_url'),
            'expiration_time' => $expiration,
        ];
    }
}
