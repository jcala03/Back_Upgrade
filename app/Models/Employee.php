<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    protected $fillable = [
        'user_id', 'branch_id', 'name', 'phone', 'job_title', 'specialty', 'notes', 'is_active', 'hire_date',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'branch_id' => 'integer',
        'is_active' => 'boolean',
        'hire_date' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function branchAssignments(): HasMany
    {
        return $this->hasMany(EmployeeBranchAssignment::class);
    }

    public function workSchedules(): HasMany
    {
        return $this->hasMany(EmployeeWorkSchedule::class);
    }

    public function scheduleOverrides(): HasMany
    {
        return $this->hasMany(EmployeeScheduleOverride::class);
    }

    public function leaves(): HasMany
    {
        return $this->hasMany(EmployeeLeave::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'assigned_employee_id');
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'responsible_employee_id');
    }

    public function goals(): HasMany
    {
        return $this->hasMany(Goal::class);
    }

    public function salesQuotations(): HasMany
    {
        return $this->hasMany(Quotation::class, 'sales_employee_id');
    }

    public function salesOrders(): HasMany
    {
        return $this->hasMany(Order::class, 'sales_employee_id');
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(EmployeeCommission::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
