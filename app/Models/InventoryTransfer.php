<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryTransfer extends Model
{
    public const STATUS_REQUESTED = 'requested';

    public const STATUS_IN_TRANSIT = 'in_transit';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'number',
        'source_branch_id',
        'destination_branch_id',
        'status',
        'requested_by',
        'dispatched_by',
        'received_by',
        'cancelled_by',
        'requested_at',
        'dispatched_at',
        'received_at',
        'cancelled_at',
        'cancellation_reason',
        'notes',
        'client_request_key',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'dispatched_at' => 'datetime',
        'received_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public static function statuses(): array
    {
        return [
            self::STATUS_REQUESTED,
            self::STATUS_IN_TRANSIT,
            self::STATUS_RECEIVED,
            self::STATUS_CANCELLED,
        ];
    }

    public function sourceBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'source_branch_id');
    }

    public function destinationBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'destination_branch_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function dispatcher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispatched_by');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InventoryTransferItem::class);
    }
}
