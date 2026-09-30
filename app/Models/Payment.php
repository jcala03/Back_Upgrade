<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    public const METHOD_CASH = 'cash';

    public const METHOD_TRANSFER = 'transfer';

    public const METHOD_CARD_TERMINAL = 'card_terminal';

    public const METHOD_WOMPI = 'wompi';

    public const PROVIDER_WOMPI = 'wompi';

    public const METHOD_OTHER = 'other';

    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REFUNDED = 'refunded';

    protected $fillable = [
        'order_id', 'amount', 'method', 'status', 'provider', 'reference', 'transaction_id', 'notes', 'paid_at', 'created_by', 'metadata',
        'currency', 'idempotency_key', 'idempotency_fingerprint', 'provider_status', 'expires_at',
        'failure_code', 'failure_reason', 'reconciliation_required_at', 'reconciliation_reason',
    ];

    protected $casts = [
        'amount' => 'integer', 'paid_at' => 'datetime', 'metadata' => 'array',
        'expires_at' => 'datetime', 'reconciliation_required_at' => 'datetime',
    ];

    public static function methods(): array
    {
        return [self::METHOD_CASH, self::METHOD_TRANSFER, self::METHOD_CARD_TERMINAL, self::METHOD_WOMPI, self::METHOD_OTHER];
    }

    public static function statuses(): array
    {
        return [self::STATUS_PENDING, self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_REFUNDED];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reconciliationReviews(): HasMany
    {
        return $this->hasMany(PaymentReconciliationReview::class);
    }
}
