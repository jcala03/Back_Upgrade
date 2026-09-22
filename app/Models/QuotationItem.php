<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuotationItem extends Model
{
    public const ITEM_TYPE_PRODUCT = 'product';

    public const ITEM_TYPE_SERVICE = 'service';

    protected $fillable = [
        'quotation_id', 'item_type', 'product_id', 'product_variant_id', 'service_id',
        'product_name', 'product_slug', 'product_sku', 'variant_name', 'variant_sku',
        'variant_specs', 'service_name', 'service_description', 'unit_price',
        'unit_cost', 'quantity', 'subtotal', 'discount_amount', 'total',
    ];

    protected $casts = [
        'variant_specs' => 'array', 'unit_price' => 'integer', 'unit_cost' => 'integer',
        'quantity' => 'integer', 'subtotal' => 'integer', 'discount_amount' => 'integer',
        'total' => 'integer',
    ];

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public static function itemTypes(): array
    {
        return [self::ITEM_TYPE_PRODUCT, self::ITEM_TYPE_SERVICE];
    }
}
