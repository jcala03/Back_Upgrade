<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerVehicle extends Model
{
    protected $fillable = [
        'customer_id', 'vehicle_brand_id', 'vehicle_model_id', 'vehicle_version_id',
        'year', 'plate', 'vin', 'color', 'nickname', 'notes', 'is_active',
        'created_by', 'updated_by',
    ];

    protected $casts = ['year' => 'integer', 'is_active' => 'boolean'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function vehicleBrand(): BelongsTo
    {
        return $this->belongsTo(VehicleBrand::class);
    }

    public function vehicleModel(): BelongsTo
    {
        return $this->belongsTo(VehicleModel::class);
    }

    public function vehicleVersion(): BelongsTo
    {
        return $this->belongsTo(VehicleVersion::class);
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
