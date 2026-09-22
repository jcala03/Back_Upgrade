<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductSpecValue extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'product_category_field_id',
        'value_text',
        'value_number',
        'value_boolean',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'product_category_field_id' => 'integer',
        'value_number' => 'decimal:2',
        'value_boolean' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function field(): BelongsTo
    {
        return $this->belongsTo(ProductCategoryField::class, 'product_category_field_id');
    }
}
