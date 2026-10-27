<?php

namespace App\Contracts;

use App\Models\PushSubscription;

interface WebPushTransport
{
    /** Return the provider HTTP status. Never return/log endpoint or encryption keys. */
    public function send(PushSubscription $subscription, array $payload): int;
}
