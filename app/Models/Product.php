<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Product extends Model
{
    public const PRICING_MODE_MANUAL = 'manual';

    public const PRICING_MODE_MARKUP = 'markup';

    public const PRICING_MODE_MARGIN = 'margin';

    public const COMPATIBILITY_TYPE_UNIVERSAL = 'universal';

    public const COMPATIBILITY_TYPE_VEHICLE_SPECIFIC = 'vehicle_specific';

    protected $fillable = [
        'name',
        'normalized_name',
        'slug',
        'description',
        'category',
        'category_id',
        'product_brand_id',
        'compatibility_type',
        'sku',

        'price',
        'cost_price',
        'tax_amount',
        'extra_charges',
        'total_cost',
        'profit_amount',
        'profit_margin_percent',
        'markup_percent',
        'pricing_mode',
        'target_profit_percent',
        'commission_enabled',
        'commission_amount',

        'technical_specs',
        'main_image',
        'requires_shipping',
        'weight_grams',
        'length_mm',
        'width_mm',
        'height_mm',
        'country_of_origin',
        'hs_code',
        'customs_description',

        'is_visible',
        'is_featured',
        'is_active',
    ];

    protected $casts = [
        'price' => 'integer',
        'cost_price' => 'integer',
        'tax_amount' => 'integer',
        'extra_charges' => 'integer',
        'total_cost' => 'integer',
        'profit_amount' => 'integer',
        'profit_margin_percent' => 'decimal:2',
        'markup_percent' => 'decimal:2',
        'target_profit_percent' => 'decimal:2',
        'commission_enabled' => 'boolean',
        'commission_amount' => 'integer',

        'technical_specs' => 'array',
        'requires_shipping' => 'boolean',
        'weight_grams' => 'integer',
        'length_mm' => 'integer',
        'width_mm' => 'integer',
        'height_mm' => 'integer',

        'is_visible' => 'boolean',
        'is_featured' => 'boolean',
        'is_active' => 'boolean',
    ];

    protected $hidden = [
        'commission_enabled',
        'commission_amount',
    ];

    protected $appends = [
        'image_url',
        'has_variants',
        'lowest_variant_price',
        'is_universal',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function productCategory(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function productBrand(): BelongsTo
    {
        return $this->belongsTo(ProductBrand::class);
    }

    public function specValues(): HasMany
    {
        return $this->hasMany(ProductSpecValue::class);
    }

    public function vehicleCompatibilities(): HasMany
    {
        return $this->hasMany(ProductVehicleCompatibility::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function activeVariants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function visibleVariants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)
            ->where('is_active', true)
            ->where('is_visible', true)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function employeeCommissions(): HasMany
    {
        return $this->hasMany(EmployeeCommission::class);
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

    public function usesUniversalCompatibility(): bool
    {
        return $this->compatibility_type === self::COMPATIBILITY_TYPE_UNIVERSAL;
    }

    public function usesVehicleSpecificCompatibility(): bool
    {
        return $this->compatibility_type === self::COMPATIBILITY_TYPE_VEHICLE_SPECIFIC;
    }

    public function generatesCommission(): bool
    {
        return $this->commission_enabled
            && ($this->commissionAmount() ?? 0) > 0;
    }

    public function commissionAmount(): ?int
    {
        return $this->commission_amount !== null
            ? (int) $this->commission_amount
            : null;
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

    protected function isUniversal(): Attribute
    {
        return Attribute::get(
            fn () => $this->usesUniversalCompatibility()
        );
    }

    protected function hasVariants(): Attribute
    {
        return Attribute::get(function () {
            if (! $this->relationLoaded('variants')) {
                return false;
            }

            return $this->variants->isNotEmpty();
        });
    }

    protected function lowestVariantPrice(): Attribute
    {
        return Attribute::get(function () {
            if (! $this->relationLoaded('variants')) {
                return null;
            }

            $prices = $this->variants
                ->where('is_active', true)
                ->where('is_visible', true)
                ->pluck('price')
                ->filter(fn ($price) => (int) $price > 0);

            return $prices->isNotEmpty()
                ? (int) $prices->min()
                : null;
        });
    }
}
