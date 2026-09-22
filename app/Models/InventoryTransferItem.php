<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryTransferItem extends Model
{
    protected $fillable = [
        'inventory_transfer_id',
        'inventory_item_id',
        'product_id',
        'product_variant_id',
        'product_name_snapshot',
        'variant_name_snapshot',
        'sku_snapshot',
        'quantity',
    ];

    protected $casts = [
        'quantity' => 'integer',
    ];

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(InventoryTransfer::class, 'inventory_transfer_id');
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }
}
