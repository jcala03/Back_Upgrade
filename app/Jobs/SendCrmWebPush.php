<?php

namespace App\Jobs;

use App\Contracts\WebPushTransport;
use App\Models\CrmNotification;
use App\Models\PushSubscription;
use App\Services\CrmWebPush;
use App\Support\PushEndpoint;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

class SendCrmWebPush implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public int $uniqueFor = 3600;

    public function __construct(public int $notificationId, public int $subscriptionId) {}

    public function uniqueId(): string
    {
        return $this->notificationId.':'.$this->subscriptionId;
    }

    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(WebPushTransport $transport, CrmWebPush $push): void
    {
        if (! CrmWebPush::configured()) {
            return;
        }
        $notification = CrmNotification::find($this->notificationId);
        $subscription = PushSubscription::with('user.capabilities')->find($this->subscriptionId);
        if (! $notification || ! $subscription?->is_active || ! $subscription->user
            || ! $push->eligible($notification, $subscription->user)) {
            return;
        }
        if ($subscription->expires_at?->isPast() || ! PushEndpoint::valid($subscription->endpoint)) {
            $subscription->update(['is_active' => false]);

            return;
        }
        $sentKey = 'crm-push-sent:'.$this->uniqueId();
        if (Cache::has($sentKey)) {
            return;
        }
        try {
            $status = $transport->send(
                $subscription,
                $push->payload($notification, $subscription->user),
                $push->deliveryOptions($notification),
            );
        } catch (Throwable) {
            // Do not leak provider request URLs or crypto keys into failed_jobs/logs.
            throw new RuntimeException('Temporary Web Push transport failure.');
        }
        if (in_array($status, [404, 410], true)) {
            $subscription->update(['is_active' => false]);
        } elseif ($status >= 200 && $status < 300) {
            Cache::put($sentKey, true, now()->addDays(2));
            $subscription->update(['last_sent_at' => now()]);
        } elseif ($status === 429 || $status >= 500) {
            throw new RuntimeException('Temporary Web Push provider failure.');
        } else {
            throw new RuntimeException('Web Push rejected: check VAPID configuration and subscription (HTTP '.$status.').');
        }
    }
}
