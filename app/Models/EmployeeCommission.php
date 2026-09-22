<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeCommission extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_EARNED = 'earned';

    public const STATUS_VOIDED = 'voided';

    public const VOID_REASON_ORDER_CANCELLED = 'order_cancelled';

    protected $fillable = [
        'employee_id',
        'branch_id',
        'order_id',
        'order_item_id',
        'product_id',
        'product_variant_id',
        'product_name_snapshot',
        'variant_name_snapshot',
        'sku_snapshot',
        'quantity',
        'unit_commission',
        'amount',
        'status',
        'earned_at',
        'voided_at',
        'voided_by',
        'void_reason',
    ];

    protected $casts = [
        'employee_id' => 'integer',
        'branch_id' => 'integer',
        'order_id' => 'integer',
        'order_item_id' => 'integer',
        'product_id' => 'integer',
        'product_variant_id' => 'integer',
        'quantity' => 'integer',
        'unit_commission' => 'integer',
        'amount' => 'integer',
        'earned_at' => 'datetime',
        'voided_at' => 'datetime',
        'voided_by' => 'integer',
    ];

    public static function statuses(): array
    {
        return [self::STATUS_PENDING, self::STATUS_EARNED, self::STATUS_VOIDED];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}
