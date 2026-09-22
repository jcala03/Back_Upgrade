<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryStock extends Model
{
    protected $fillable = [
        'branch_id',
        'inventory_item_id',
        'quantity',
        'minimum_quantity',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'minimum_quantity' => 'integer',
    ];

    protected $appends = [
        'is_low_stock',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    protected function isLowStock(): Attribute
    {
        return Attribute::get(
            fn () => $this->quantity > 0
                && $this->quantity <= $this->minimum_quantity
        );
    }
}
