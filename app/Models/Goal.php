<?php

namespace App\Models;

use App\Support\Business\BusinessContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Goal extends Model
{
    public const SOURCE_PERSONAL = 'personal';

    public const SOURCE_ASSIGNED = 'assigned';

    public const METRIC_MANUAL = 'manual';

    public const METRIC_SALES_AMOUNT = 'sales_amount';

    public const METRIC_COMMISSION_AMOUNT = 'commission_amount';

    public const METRIC_COMPLETED_JOBS = 'completed_jobs';

    public const METRIC_SALES_COUNT = 'sales_count';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const EFFECTIVE_EXPIRED = 'expired';

    protected $fillable = ['employee_id', 'created_by', 'updated_by', 'source', 'title', 'description', 'metric_type',
        'target_value', 'current_value', 'unit', 'starts_on', 'due_on', 'status', 'completed_at', 'completed_by',
        'cancelled_at', 'cancelled_by', 'cancellation_reason'];

    protected $casts = ['target_value' => 'decimal:2', 'current_value' => 'decimal:2', 'starts_on' => 'immutable_date',
        'due_on' => 'immutable_date', 'completed_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime'];

    protected $appends = ['effective_status', 'progress_percentage', 'is_overdue', 'completed_after_due_date'];

    public static function sources(): array
    {
        return [self::SOURCE_PERSONAL, self::SOURCE_ASSIGNED];
    }

    public static function statuses(): array
    {
        return [self::STATUS_ACTIVE, self::STATUS_COMPLETED, self::STATUS_CANCELLED];
    }

    public static function effectiveStatuses(): array
    {
        return [...self::statuses(), self::EFFECTIVE_EXPIRED];
    }

    public function getEffectiveStatusAttribute(): string
    {
        return $this->status === self::STATUS_ACTIVE && $this->due_on && $this->due_on->toDateString() < BusinessContext::today()->toDateString() ? self::EFFECTIVE_EXPIRED : $this->status;
    }

    public function getProgressPercentageAttribute(): ?float
    {
        return $this->target_value !== null && (float) $this->target_value > 0 ? round(((float) $this->current_value / (float) $this->target_value) * 100, 2) : null;
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->effective_status === self::EFFECTIVE_EXPIRED;
    }

    public function getCompletedAfterDueDateAttribute(): bool
    {
        return $this->status === self::STATUS_COMPLETED && $this->due_on && $this->completed_at ? $this->completed_at->setTimezone(BusinessContext::TIMEZONE)->toDateString() > $this->due_on->toDateString() : false;
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}
