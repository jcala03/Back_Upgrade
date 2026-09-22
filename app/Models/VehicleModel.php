<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VehicleModel extends Model
{
    use HasFactory;

    protected $fillable = [
        'vehicle_brand_id',
        'name',
        'slug',
        'description',
        'is_active',
    ];

    protected $casts = [
        'vehicle_brand_id' => 'integer',
        'is_active' => 'boolean',
    ];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(VehicleBrand::class, 'vehicle_brand_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(VehicleVersion::class);
    }

    public function compatibilities(): HasMany
    {
        return $this->hasMany(ProductVehicleCompatibility::class);
    }
}
