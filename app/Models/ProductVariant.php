<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ProductVariant extends Model
{
    public const PRICING_MODE_MANUAL = 'manual';

    public const PRICING_MODE_MARKUP = 'markup';

    public const PRICING_MODE_MARGIN = 'margin';

    protected $hidden = ['product'];

    protected $fillable = [
        'product_id',
        'vehicle_multimedia_system_id',
        'compatibility_type',

        'name',
        'normalized_name',
        'sku',
        'attributes',

        'cost_price',
        'tax_amount',
        'extra_charges',
        'total_cost',

        'price',
        'profit_amount',
        'profit_margin_percent',
        'markup_percent',
        'pricing_mode',
        'target_profit_percent',

        'main_image',
        'weight_grams',
        'length_mm',
        'width_mm',
        'height_mm',
        'country_of_origin',
        'hs_code',
        'customs_description',

        'is_default',
        'is_active',
        'is_visible',
        'sort_order',
    ];

    protected $casts = [
        'attributes' => 'array',

        'cost_price' => 'integer',
        'tax_amount' => 'integer',
        'extra_charges' => 'integer',
        'total_cost' => 'integer',

        'price' => 'integer',
        'profit_amount' => 'integer',
        'profit_margin_percent' => 'decimal:2',
        'markup_percent' => 'decimal:2',
        'target_profit_percent' => 'decimal:2',
        'weight_grams' => 'integer',
        'length_mm' => 'integer',
        'width_mm' => 'integer',
        'height_mm' => 'integer',

        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'is_visible' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected $appends = [
        'display_name',
        'image_url',
        'effective_compatibility_type',
        'specs',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function vehicleMultimediaSystem(): BelongsTo
    {
        return $this->belongsTo(VehicleMultimediaSystem::class);
    }

    public function specValues(): HasMany
    {
        return $this->hasMany(ProductVariantSpecValue::class);
    }

    public function vehicleCompatibilities(): HasMany
    {
        return $this->hasMany(ProductVariantCompatibility::class);
    }

    public function inventoryItem(): HasOne
    {
        return $this->hasOne(InventoryItem::class);
    }

    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    public function inventoryTransferItems(): HasMany
    {
        return $this->hasMany(InventoryTransferItem::class);
    }

    public function employeeCommissions(): HasMany
    {
        return $this->hasMany(EmployeeCommission::class);
    }

    public function resolvedCompatibilityType(): ?string
    {
        return $this->compatibility_type
            ?? $this->product?->compatibility_type;
    }

    public function resolvedLogistics(): array
    {
        $fields = [
            'weight_grams', 'length_mm', 'width_mm', 'height_mm',
            'country_of_origin', 'hs_code', 'customs_description',
        ];

        return collect($fields)->mapWithKeys(fn (string $field) => [
            $field => $this->getAttribute($field) ?? $this->product?->getAttribute($field),
        ])->all();
    }

    protected function effectiveCompatibilityType(): Attribute
    {
        return Attribute::get(fn () => $this->resolvedCompatibilityType());
    }

    protected function specs(): Attribute
    {
        return Attribute::get(function () {
            if (! $this->relationLoaded('specValues')) {
                return [];
            }

            return $this->specValues->mapWithKeys(function ($specValue) {
                $value = $specValue->value_text;

                if ($specValue->value_number !== null) {
                    $value = (float) $specValue->value_number;
                } elseif ($specValue->value_boolean !== null) {
                    $value = (bool) $specValue->value_boolean;
                }

                return [$specValue->field?->field_key => $value];
            })->filter(fn ($value, $key) => $key !== null)->all();
        });
    }

    protected function displayName(): Attribute
    {
        return Attribute::get(function () {
            if ($this->name) {
                return $this->name;
            }

            if ($this->sku) {
                return $this->sku;
            }

            return 'Variante estándar';
        });
    }

    protected function imageUrl(): Attribute
    {
        return Attribute::get(function () {
            if (! $this->main_image) {
                return null;
            }

            if (str_starts_with($this->main_image, 'http')) {
                return $this->main_image;
            }

            return asset('storage/'.$this->main_image);
        });
    }
}
