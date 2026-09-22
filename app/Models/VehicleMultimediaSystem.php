<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class VehicleMultimediaSystem extends Model
{
    protected $fillable = [
        'vehicle_brand_id',
        'name',
        'slug',
        'code',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function vehicleBrand(): BelongsTo
    {
        return $this->belongsTo(VehicleBrand::class);
    }

    public function vehicleVersions(): BelongsToMany
    {
        return $this->belongsToMany(
            VehicleVersion::class,
            'vehicle_version_multimedia_system'
        )->withPivot(['year_from', 'year_to'])->withTimestamps();
    }
}
