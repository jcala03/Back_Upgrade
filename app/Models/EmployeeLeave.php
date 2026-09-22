<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeLeave extends Model
{
    public const TYPE_VACATION = 'vacation';

    public const TYPE_PERMISSION = 'permission';

    public const TYPE_SICK_LEAVE = 'sick_leave';

    public const TYPE_ABSENCE = 'absence';

    public const TYPE_OTHER = 'other';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'employee_id', 'type', 'starts_at', 'ends_at', 'status', 'reason', 'notes',
        'approved_by', 'approved_at', 'cancelled_by', 'cancelled_at',
    ];

    protected $casts = [
        'employee_id' => 'integer',
        'starts_at' => 'immutable_datetime',
        'ends_at' => 'immutable_datetime',
        'approved_at' => 'immutable_datetime',
        'cancelled_at' => 'immutable_datetime',
    ];

    public static function types(): array
    {
        return [self::TYPE_VACATION, self::TYPE_PERMISSION, self::TYPE_SICK_LEAVE, self::TYPE_ABSENCE, self::TYPE_OTHER];
    }

    public static function statuses(): array
    {
        return [self::STATUS_APPROVED, self::STATUS_CANCELLED];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function scopeOverlapping(Builder $query, string $startsAt, string $endsAt): Builder
    {
        return $query->where('starts_at', '<', $endsAt)->where('ends_at', '>', $startsAt);
    }
}
