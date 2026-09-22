<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VehicleVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'vehicle_model_id',
        'name',
        'year_from',
        'year_to',
        'description',
        'is_active',
    ];

    protected $casts = [
        'vehicle_model_id' => 'integer',
        'year_from' => 'integer',
        'year_to' => 'integer',
        'is_active' => 'boolean',
    ];

    protected $appends = [
        'display_name',
    ];

    public function model(): BelongsTo
    {
        return $this->belongsTo(VehicleModel::class, 'vehicle_model_id');
    }

    public function compatibilities(): HasMany
    {
        return $this->hasMany(ProductVehicleCompatibility::class);
    }

    public function multimediaSystems(): BelongsToMany
    {
        return $this->belongsToMany(
            VehicleMultimediaSystem::class,
            'vehicle_version_multimedia_system'
        )->withPivot(['year_from', 'year_to'])->withTimestamps();
    }

    public function getDisplayNameAttribute(): string
    {
        $name = $this->name ? "{$this->name} " : '';
        $yearTo = $this->year_to ?: 'Actual';

        return trim("{$name}{$this->year_from}-{$yearTo}");
    }
}
