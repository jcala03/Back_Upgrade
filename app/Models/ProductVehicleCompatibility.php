<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVehicleCompatibility extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'vehicle_brand_id',
        'vehicle_model_id',
        'vehicle_version_id',
        'notes',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'vehicle_brand_id' => 'integer',
        'vehicle_model_id' => 'integer',
        'vehicle_version_id' => 'integer',
    ];

    protected $appends = [
        'vehicle_label',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
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

    public function getVehicleLabelAttribute(): string
    {
        $parts = array_filter([
            $this->vehicleBrand?->name,
            $this->vehicleModel?->name,
            $this->vehicleVersion?->display_name,
        ]);

        return implode(' ', $parts);
    }
}
