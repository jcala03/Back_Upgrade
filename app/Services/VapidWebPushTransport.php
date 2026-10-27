<?php

namespace App\Services;

use App\Contracts\WebPushTransport;
use App\Models\PushSubscription;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Log\LoggerInterface;

class VapidWebPushTransport implements WebPushTransport
{
    protected function client(): WebPush
    {
        return new WebPush(['VAPID' => [
            'subject' => config('webpush.subject'),
            'publicKey' => config('webpush.public_key'),
            'privateKey' => config('webpush.private_key'),
        ]], ['TTL' => 3600, 'urgency' => 'normal'], 15, [
            'allow_redirects' => false, 'connect_timeout' => 5,
        ], app(LoggerInterface::class));
    }

    public function send(PushSubscription $subscription, array $payload, array $options = []): int
    {
        $report = $this->client()->sendOneNotification(Subscription::create([
            'endpoint' => $subscription->endpoint,
            'publicKey' => $subscription->p256dh,
            'authToken' => $subscription->auth,
            'contentEncoding' => 'aes128gcm',
        ]), json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $options);

        return $report->isSuccess() ? 201 : ($report->getResponse()?->getStatusCode() ?? 503);
    }
}
