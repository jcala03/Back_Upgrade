<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    protected $fillable = [
        'name', 'phone', 'phone_normalized', 'email', 'document', 'city',
        'address', 'notes', 'is_active', 'created_by', 'updated_by',
    ];

    protected $casts = ['is_active' => 'boolean'];

    protected $hidden = ['phone_normalized'];

    public function vehicles(): HasMany
    {
        return $this->hasMany(CustomerVehicle::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
