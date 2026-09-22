<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeScheduleOverride extends Model
{
    public const TYPE_WORKING = 'working';

    public const TYPE_NON_WORKING = 'non_working';

    protected $fillable = [
        'employee_id', 'date', 'type', 'starts_at', 'ends_at', 'reason', 'created_by',
    ];

    protected $casts = [
        'employee_id' => 'integer',
        'date' => 'immutable_date',
    ];

    public static function types(): array
    {
        return [self::TYPE_WORKING, self::TYPE_NON_WORKING];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeOnDate(Builder $query, string $date): Builder
    {
        return $query->whereDate('date', $date);
    }
}
