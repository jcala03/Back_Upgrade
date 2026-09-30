<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentReconciliationReview extends Model
{
    public const STATE_DETECTED = 'detected';

    public const STATE_UNDER_REVIEW = 'under_review';

    public const STATE_RESOLVED = 'resolved';

    protected $fillable = [
        'payment_id', 'order_id', 'webhook_event_id', 'parent_review_id', 'reason', 'state',
        'latest_decision', 'detection_key', 'assigned_to', 'detected_at', 'review_started_at',
        'resolved_at', 'resolved_by',
    ];

    protected $hidden = ['detection_key'];

    protected $casts = [
        'detected_at' => 'datetime',
        'review_started_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public static function states(): array
    {
        return [self::STATE_DETECTED, self::STATE_UNDER_REVIEW, self::STATE_RESOLVED];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function webhookEvent(): BelongsTo
    {
        return $this->belongsTo(WebhookEvent::class);
    }

    public function parentReview(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_review_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(PaymentReconciliationAction::class, 'review_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
