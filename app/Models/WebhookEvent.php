<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookEvent extends Model
{
    public const STATUS_RECEIVED = 'received';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_REQUIRES_RECONCILIATION = 'requires_reconciliation';

    public const STATUS_IGNORED = 'ignored';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'provider', 'event_key', 'event_type', 'provider_transaction_id', 'payload_hash',
        'status', 'received_at', 'processed_at', 'metadata', 'last_error',
    ];

    protected $casts = [
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
        'metadata' => 'array',
    ];
}
