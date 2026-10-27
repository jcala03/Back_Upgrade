<?php

use App\Models\CrmNotification;

return [
    'enabled' => env('WEBPUSH_ENABLED', false),
    'public_key' => env('WEBPUSH_VAPID_PUBLIC_KEY', ''),
    'private_key' => env('WEBPUSH_VAPID_PRIVATE_KEY', ''),
    'subject' => env('WEBPUSH_VAPID_SUBJECT', ''),
    // Do not inherit a synchronous business queue: provider failures must not delay sales.
    'connection' => env('WEBPUSH_QUEUE_CONNECTION', 'database'),
    'queue' => env('WEBPUSH_QUEUE', 'web-push'),
    'types' => [
        CrmNotification::TYPE_STOCK_LOW, CrmNotification::TYPE_STOCK_OUT,
        CrmNotification::TYPE_ORDER_ECOMMERCE_PENDING, CrmNotification::TYPE_PAYMENT_RECEIVED,
        CrmNotification::TYPE_PAYMENT_RECONCILIATION_REQUIRED,
        CrmNotification::TYPE_QUOTATION_EXPIRING, CrmNotification::TYPE_QUOTATION_CONVERTED,
        CrmNotification::TYPE_TASK_ASSIGNED, CrmNotification::TYPE_TASK_REASSIGNED,
        CrmNotification::TYPE_TASK_CANCELLED, CrmNotification::TYPE_APPOINTMENT_ASSIGNED,
        CrmNotification::TYPE_APPOINTMENT_RESCHEDULED, CrmNotification::TYPE_APPOINTMENT_REASSIGNED,
        CrmNotification::TYPE_APPOINTMENT_CANCELLED,
    ],
];
