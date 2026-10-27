<?php

namespace App\Contracts;

use App\Models\PushSubscription;

interface WebPushTransport
{
    /**
     * Return the provider HTTP status. Never return/log endpoint or encryption keys.
     *
     * @param  array{TTL?: int, urgency?: string}  $options  Per-notification delivery options, not VAPID auth.
     */
    public function send(PushSubscription $subscription, array $payload, array $options = []): int;
}
