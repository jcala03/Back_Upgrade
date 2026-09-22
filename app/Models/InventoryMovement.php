<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryMovement extends Model
{
    public const TYPE_ENTRY = 'entry';

    public const TYPE_EXIT = 'exit';

    public const TYPE_ADJUSTMENT = 'adjustment';

    public const TYPE_SALE = 'sale';

    public const TYPE_SALE_REVERSAL = 'sale_reversal';

    public const TYPE_TRANSFER_OUT = 'transfer_out';

    public const TYPE_TRANSFER_IN = 'transfer_in';

    protected $fillable = [
        'product_id',
        'product_variant_id',
        'branch_id',
        'inventory_item_id',
        'inventory_transfer_item_id',
        'type',
        'quantity_delta',
        'stock_before',
        'stock_after',
        'reason',
        'notes',
        'created_by',
        'reference_type',
        'reference_id',
    ];

    public static function types(): array
    {
        return [
            self::TYPE_ENTRY,
            self::TYPE_EXIT,
            self::TYPE_ADJUSTMENT,
            self::TYPE_SALE,
            self::TYPE_SALE_REVERSAL,
            self::TYPE_TRANSFER_OUT,
            self::TYPE_TRANSFER_IN,
        ];
    }

    protected $casts = [
        'quantity_delta' => 'integer',
        'stock_before' => 'integer',
        'stock_after' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function inventoryTransferItem(): BelongsTo
    {
        return $this->belongsTo(InventoryTransferItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
