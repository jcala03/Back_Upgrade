<?php

namespace App\Services;

use App\Exceptions\WompiWebhookException;

class WompiWebhookSignatureService
{
    public function validate(array $payload, ?string $header): void
    {
        $secret = (string) config('services.wompi.events_secret');
        $environment = WompiClient::environment();
        if (! str_starts_with($secret, $environment.'_events_') || strlen($secret) <= strlen($environment.'_events_')) {
            throw new WompiWebhookException('WOMPI_NOT_CONFIGURED', 503);
        }
        if (($payload['environment'] ?? null) !== $environment) {
            throw new WompiWebhookException('WOMPI_ENVIRONMENT_MISMATCH', 400);
        }
        $properties = $payload['signature']['properties'] ?? null;
        if (! is_array($properties) || ! array_is_list($properties) || $properties === []
            || ! is_int($payload['timestamp'] ?? null) || $payload['timestamp'] < 1 || ! is_array($payload['data'] ?? null)) {
            throw new WompiWebhookException('WOMPI_MALFORMED_EVENT');
        }
        $values = '';
        foreach ($properties as $path) {
            if (! is_string($path) || ! preg_match('/^[a-zA-Z0-9_]+(?:\.[a-zA-Z0-9_]+)*$/D', $path)) {
                throw new WompiWebhookException('WOMPI_MALFORMED_EVENT');
            }
            $value = $payload['data'];
            foreach (explode('.', $path) as $part) {
                if (! is_array($value) || ! array_key_exists($part, $value)) {
                    throw new WompiWebhookException('WOMPI_MALFORMED_EVENT');
                }
                $value = $value[$part];
            }
            // Wompi's documented string/integer scalar concatenation. Fail closed for ambiguous objects/floats.
            if (! is_string($value) && ! is_int($value)) {
                throw new WompiWebhookException('WOMPI_MALFORMED_EVENT');
            }
            $values .= (string) $value;
        }
        $bodyPresent = array_key_exists('checksum', $payload['signature']);
        $body = $bodyPresent ? $payload['signature']['checksum'] : null;
        foreach ([$header, $body] as $checksum) {
            if ($checksum !== null && (! is_string($checksum) || ! preg_match('/^[a-fA-F0-9]{64}$/D', $checksum))) {
                throw new WompiWebhookException('WOMPI_INVALID_CHECKSUM', 401);
            }
        }
        if (($bodyPresent && $body === null) || ($header === null && $body === null)
            || ($header !== null && $body !== null && ! hash_equals(strtolower($body), strtolower($header)))
            || ! hash_equals(hash('sha256', $values.$payload['timestamp'].$secret), strtolower($header ?? $body))) {
            throw new WompiWebhookException('WOMPI_INVALID_CHECKSUM', 401);
        }
    }
}
