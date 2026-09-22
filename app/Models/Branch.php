<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    protected $fillable = [
        'code', 'slug', 'name', 'city', 'is_active', 'ecommerce_priority',
        'country_code', 'state', 'postal_code', 'address_line1', 'address_line2',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'ecommerce_priority' => 'integer',
    ];

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function assignmentsFrom(): HasMany
    {
        return $this->hasMany(EmployeeBranchAssignment::class, 'from_branch_id');
    }

    public function assignmentsTo(): HasMany
    {
        return $this->hasMany(EmployeeBranchAssignment::class, 'to_branch_id');
    }

    public function inventoryStocks(): HasMany
    {
        return $this->hasMany(InventoryStock::class);
    }

    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    public function outgoingTransfers(): HasMany
    {
        return $this->hasMany(InventoryTransfer::class, 'source_branch_id');
    }

    public function incomingTransfers(): HasMany
    {
        return $this->hasMany(InventoryTransfer::class, 'destination_branch_id');
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function employeeCommissions(): HasMany
    {
        return $this->hasMany(EmployeeCommission::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
