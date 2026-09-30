<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PaymentReconciliationAction extends Model
{
    public const UPDATED_AT = null;

    public const ACTION_DETECTED = 'detected';

    public const ACTION_REVIEW_STARTED = 'review_started';

    public const ACTION_DECISION_RECORDED = 'decision_recorded';

    public const ACTION_RESOLVED = 'resolved';

    protected $fillable = [
        'review_id', 'actor_id', 'action', 'previous_state', 'new_state', 'decision',
        'justification', 'evidence_reference', 'canonical_payment_id', 'idempotency_key',
        'request_fingerprint', 'created_at',
    ];

    protected $hidden = ['idempotency_key', 'request_fingerprint'];

    protected $casts = ['created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Reconciliation actions are append-only.'));
        static::deleting(fn () => throw new LogicException('Reconciliation actions are append-only.'));
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(PaymentReconciliationReview::class, 'review_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function canonicalPayment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'canonical_payment_id');
    }
}
