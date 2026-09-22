<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVariantCompatibility extends Model
{
    protected $fillable = [
        'product_variant_id',
        'vehicle_brand_id',
        'vehicle_model_id',
        'vehicle_version_id',
        'vehicle_multimedia_system_id',
        'year_from',
        'year_to',
        'notes',
    ];

    protected $casts = [
        'product_variant_id' => 'integer',
        'vehicle_brand_id' => 'integer',
        'vehicle_model_id' => 'integer',
        'vehicle_version_id' => 'integer',
        'vehicle_multimedia_system_id' => 'integer',
        'year_from' => 'integer',
        'year_to' => 'integer',
    ];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
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

    public function vehicleMultimediaSystem(): BelongsTo
    {
        return $this->belongsTo(VehicleMultimediaSystem::class);
    }
}
