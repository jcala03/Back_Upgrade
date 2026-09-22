<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderAddress extends Model
{
    public const TYPE_SHIPPING = 'shipping';

    protected $attributes = [
        'type' => self::TYPE_SHIPPING,
    ];

    protected $fillable = [
        'order_id', 'type', 'recipient_name', 'recipient_phone', 'country_code',
        'state', 'city', 'postal_code', 'address_line1', 'address_line2',
        'delivery_notes',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
