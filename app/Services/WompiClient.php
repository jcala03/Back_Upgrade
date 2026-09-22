<?php

namespace App\Services;

use App\Exceptions\WompiWebhookException;
use App\Support\Payments\WompiTransaction;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class WompiClient
{
    public static function environment(): string
    {
        return match (rtrim((string) config('services.wompi.base_url'), '/')) {
            'https://sandbox.wompi.co/v1' => 'test',
            'https://production.wompi.co/v1' => 'prod',
            default => throw new WompiWebhookException('WOMPI_NOT_CONFIGURED', 503),
        };
    }

    public function getTransaction(string $transactionId): WompiTransaction
    {
        $environment = self::environment();
        $key = (string) config('services.wompi.private_key');
        // Reject malformed credentials before the HTTP library can quote a bad header in an exception.
        if (! str_starts_with($key, 'prv_'.$environment.'_') || strlen($key) <= strlen('prv_'.$environment.'_')
            || ! preg_match('/^[\x21-\x7E]+$/D', $key)) {
            throw new WompiWebhookException('WOMPI_NOT_CONFIGURED', 503);
        }
        // Only a safe GET, at most two attempts. Never follow redirects with credentials.
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $response = Http::acceptJson()->withToken($key)->connectTimeout(3)->timeout(10)
                    ->withoutRedirecting()->get(rtrim(config('services.wompi.base_url'), '/').'/transactions/'.rawurlencode($transactionId));
            } catch (ConnectionException) {
                if ($attempt === 0) {
                    continue;
                }
                throw new WompiWebhookException('WOMPI_UNAVAILABLE', 503);
            }
            if ($response->serverError() && $attempt === 0) {
                continue;
            }
            if ($response->status() === 404) {
                throw new WompiWebhookException('WOMPI_TRANSACTION_NOT_FOUND', 503);
            }
            if (in_array($response->status(), [401, 403], true)) {
                throw new WompiWebhookException('WOMPI_NOT_CONFIGURED', 503);
            }
            if (! $response->successful()) {
                throw new WompiWebhookException('WOMPI_UNAVAILABLE', 503);
            }

            return WompiTransaction::fromResponse($response->json('data'));
        }
        throw new WompiWebhookException('WOMPI_UNAVAILABLE', 503);
    }
}
