<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVariantSpecValue extends Model
{
    protected $fillable = [
        'product_variant_id',
        'product_category_field_id',
        'value_text',
        'value_number',
        'value_boolean',
    ];

    protected $casts = [
        'product_variant_id' => 'integer',
        'product_category_field_id' => 'integer',
        'value_number' => 'decimal:2',
        'value_boolean' => 'boolean',
    ];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function field(): BelongsTo
    {
        return $this->belongsTo(ProductCategoryField::class, 'product_category_field_id');
    }
}
