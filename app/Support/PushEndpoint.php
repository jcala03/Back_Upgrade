<?php

namespace App\Support;

final class PushEndpoint
{
    public static function valid(string $endpoint): bool
    {
        $parts = parse_url($endpoint);
        if (! $parts || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['fragment']) || (isset($parts['port']) && $parts['port'] !== 443)) {
            return false;
        }
        $host = strtolower($parts['host'] ?? '');

        // Only browser push services. Never let a subscription turn the worker into an SSRF client.
        return $host === 'fcm.googleapis.com'
            || $host === 'updates.push.services.mozilla.com'
            || $host === 'push.services.mozilla.com'
            || $host === 'web.push.apple.com'
            || str_ends_with($host, '.push.apple.com')
            || str_ends_with($host, '.notify.windows.com');
    }

    public static function keyValid(string $value, int $bytes): bool
    {
        if (! preg_match('/^[A-Za-z0-9_-]+$/D', $value)) {
            return false;
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded !== false && strlen($decoded) === $bytes
            && ($bytes !== 65 || $decoded[0] === "\x04");
    }
}
