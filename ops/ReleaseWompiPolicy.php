<?php

// Deployment-only, offline checks. Never contact Wompi or initialize a payment.
final class ReleaseWompiPolicy
{
    public static function checks(array $configuration): array
    {
        $checks = [];
        foreach ([
            'public_key' => ['WOMPI_PUBLIC_KEY', 'pub_prod_'],
            'private_key' => ['WOMPI_PRIVATE_KEY', 'prv_prod_'],
            'integrity_secret' => ['WOMPI_INTEGRITY_SECRET', 'prod_integrity_'],
            'events_secret' => ['WOMPI_EVENTS_SECRET', 'prod_events_'],
        ] as $key => [$variable, $prefix]) {
            $value = $configuration[$key] ?? null;
            // Match the runtime's environment prefixes and reject empty suffixes,
            // whitespace/control characters and invalid header characters before
            // any provider request. Do not assume a fixed issued-key length.
            $checks[$variable.'_production_format'] = is_string($value)
                && str_starts_with($value, $prefix)
                && strlen($value) > strlen($prefix)
                && preg_match('/^[\x21-\x7E]+$/D', $value) === 1;
        }
        $minutes = $configuration['reservation_minutes'] ?? null;
        $checks['WOMPI_RESERVATION_MINUTES_valid'] = is_int($minutes) && $minutes >= 1 && $minutes <= 1440;

        return $checks;
    }
}
