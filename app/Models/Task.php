<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Task extends Model
{
    public const PRIORITY_LOW = 'low';

    public const PRIORITY_NORMAL = 'normal';

    public const PRIORITY_HIGH = 'high';

    public const PRIORITY_URGENT = 'urgent';

    public const STATUS_PENDING = 'pending';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'assigned_employee_id', 'branch_id', 'title', 'description', 'priority', 'status', 'due_at',
        'scheduled_starts_at', 'scheduled_ends_at', 'created_by', 'updated_by',
        'started_at', 'completed_at', 'cancelled_by', 'cancelled_at', 'cancellation_reason',
        'availability_override', 'availability_override_reason', 'availability_overridden_by',
        'availability_overridden_at',
    ];

    protected $casts = [
        'assigned_employee_id' => 'integer',
        'branch_id' => 'integer',
        'due_at' => 'immutable_datetime',
        'scheduled_starts_at' => 'immutable_datetime',
        'scheduled_ends_at' => 'immutable_datetime',
        'started_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
        'cancelled_at' => 'immutable_datetime',
        'availability_override' => 'boolean',
        'availability_overridden_at' => 'immutable_datetime',
    ];

    public static function priorities(): array
    {
        return [self::PRIORITY_LOW, self::PRIORITY_NORMAL, self::PRIORITY_HIGH, self::PRIORITY_URGENT];
    }

    public static function statuses(): array
    {
        return [self::STATUS_PENDING, self::STATUS_IN_PROGRESS, self::STATUS_COMPLETED, self::STATUS_CANCELLED];
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_CANCELLED], true);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_employee_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function availabilityOverrider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'availability_overridden_by');
    }

    public function scopeBlockingAvailability(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_IN_PROGRESS])
            ->whereNotNull('scheduled_starts_at')
            ->whereNotNull('scheduled_ends_at');
    }
}
