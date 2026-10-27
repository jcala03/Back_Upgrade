<?php

namespace App\Services;

use App\Jobs\SendCrmWebPush;
use App\Models\CrmNotification;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

class CrmWebPush
{
    private const HIGH_URGENCY_TYPES = [
        CrmNotification::TYPE_TASK_ASSIGNED,
        CrmNotification::TYPE_TASK_REASSIGNED,
        CrmNotification::TYPE_TASK_CANCELLED,
        CrmNotification::TYPE_APPOINTMENT_ASSIGNED,
        CrmNotification::TYPE_APPOINTMENT_RESCHEDULED,
        CrmNotification::TYPE_APPOINTMENT_REASSIGNED,
        CrmNotification::TYPE_APPOINTMENT_CANCELLED,
        CrmNotification::TYPE_PAYMENT_RECEIVED,
        CrmNotification::TYPE_PAYMENT_RECONCILIATION_REQUIRED,
        CrmNotification::TYPE_ORDER_ECOMMERCE_PENDING,
    ];

    public static function configured(): bool
    {
        return (bool) config('webpush.enabled') && (bool) config('webpush.public_key')
            && (bool) config('webpush.private_key') && (bool) config('webpush.subject');
    }

    public function created(CrmNotification $notification): void
    {
        if (! self::configured() || ! $notification->user_id || ! in_array($notification->type, config('webpush.types', []), true)) {
            return;
        }
        try {
            PushSubscription::where('user_id', $notification->user_id)->where('is_active', true)
                ->select('id')->each(function ($subscription) use ($notification) {
                    SendCrmWebPush::dispatch($notification->id, $subscription->id)
                        ->onConnection(config('webpush.connection'))->onQueue(config('webpush.queue'))->afterCommit();
                });
        } catch (Throwable) {
            // Push is optional; never roll back a payment, sale or the internal notification.
            Log::warning('Unable to enqueue CRM Web Push.', ['notification_id' => $notification->id]);
        }
    }

    public function eligible(CrmNotification $notification, User $user): bool
    {
        $permission = match ($notification->type) {
            'stock_low', 'stock_out' => 'inventory.view',
            'order_ecommerce_pending' => 'orders.view',
            'payment_received' => 'payments.view',
            'payment_reconciliation_required' => 'payments.reconcile',
            'quotation_expiring', 'quotation_converted' => 'quotations.view',
            default => 'notifications.view',
        };

        return $user->is_active && (int) $notification->user_id === $user->id
            && $user->hasPermission('notifications.view') && $user->hasPermission($permission)
            && ! $notification->read_at && $notification->created_at->gt(now()->subDay())
            && in_array($notification->type, config('webpush.types', []), true);
    }

    public function url(CrmNotification $notification, User $user): string
    {
        [$path, $permission] = match ($notification->reference_type) {
            'product', 'product_variant' => ['/crm/inventory', 'inventory.view'],
            'order', 'payment' => ['/crm/orders', 'orders.view'],
            'quotation' => ['/crm/quotations', 'quotations.view'],
            default => ['/crm/notifications', 'notifications.view'],
        };

        return $user->hasPermission($permission) ? $path : '/crm/notifications';
    }

    /** @return array{TTL: int, urgency: string} */
    public function deliveryOptions(CrmNotification $notification): array
    {
        $urgent = in_array($notification->type, self::HIGH_URGENCY_TYPES, true);
        // A queued/retried alert must not get another full day after its existing age limit.
        $remainingLifetime = $notification->created_at->copy()->addDay()->getTimestamp() - now()->getTimestamp();

        return [
            'TTL' => max(1, min($urgent ? 86400 : 3600, $remainingLifetime)),
            'urgency' => $urgent ? 'high' : 'normal',
        ];
    }

    public function payload(CrmNotification $notification, User $user): array
    {
        return [
            'notification_id' => $notification->id, 'user_id' => $user->id,
            'title' => mb_substr($notification->title, 0, 100),
            'body' => mb_substr($notification->message, 0, 220),
            'url' => $this->url($notification, $user),
            'unread_count' => CrmNotification::where('user_id', $user->id)->whereNull('read_at')->count(),
        ];
    }
}
