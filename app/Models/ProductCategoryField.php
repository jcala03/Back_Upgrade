<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductCategoryField extends Model
{
    use HasFactory;

    public const TYPE_TEXT = 'text';

    public const TYPE_NUMBER = 'number';

    public const TYPE_SELECT = 'select';

    public const TYPE_BOOLEAN = 'boolean';

    public const SCOPE_PRODUCT = 'product';

    public const SCOPE_VARIANT = 'variant';

    protected $fillable = [
        'product_category_id',
        'name',
        'field_key',
        'type',
        'scope',
        'options',
        'is_required',
        'is_active',
        'is_filterable',
        'filter_label',
        'filter_unit',
        'sort_order',
    ];

    protected $casts = [
        'product_category_id' => 'integer',
        'options' => 'array',
        'is_required' => 'boolean',
        'is_active' => 'boolean',
        'is_filterable' => 'boolean',
        'sort_order' => 'integer',
    ];

    public static function types(): array
    {
        return [
            self::TYPE_TEXT,
            self::TYPE_NUMBER,
            self::TYPE_SELECT,
            self::TYPE_BOOLEAN,
        ];
    }

    public static function scopes(): array
    {
        return [self::SCOPE_PRODUCT, self::SCOPE_VARIANT];
    }

    public function productCategory(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class);
    }

    public function specValues(): HasMany
    {
        return $this->hasMany(ProductSpecValue::class);
    }

    public function variantSpecValues(): HasMany
    {
        return $this->hasMany(ProductVariantSpecValue::class);
    }
}
