<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeWorkSchedule extends Model
{
    protected $fillable = [
        'employee_id', 'day_of_week', 'starts_at', 'ends_at',
        'effective_from', 'effective_until', 'created_by',
    ];

    protected $casts = [
        'employee_id' => 'integer',
        'day_of_week' => 'integer',
        'effective_from' => 'immutable_date',
        'effective_until' => 'immutable_date',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeEffectiveOn(Builder $query, string $date): Builder
    {
        return $query->whereDate('effective_from', '<=', $date)
            ->where(fn (Builder $range) => $range->whereNull('effective_until')->orWhereDate('effective_until', '>=', $date));
    }
}
