<?php

namespace App\Models;

use App\Support\Business\BusinessContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderCharge extends Model
{
    public const TYPE_SHIPPING = 'shipping';

    public const TYPE_INSURANCE = 'insurance';

    public const TYPE_HANDLING = 'handling';

    public const TYPE_TAX = 'tax';

    public const TYPE_DUTY = 'duty';

    protected $attributes = [
        'currency' => BusinessContext::CURRENCY,
    ];

    protected $fillable = [
        'order_id', 'type', 'label', 'amount', 'currency', 'metadata',
    ];

    protected $casts = [
        'amount' => 'integer',
        'metadata' => 'array',
    ];

    public static function types(): array
    {
        return [
            self::TYPE_SHIPPING,
            self::TYPE_INSURANCE,
            self::TYPE_HANDLING,
            self::TYPE_TAX,
            self::TYPE_DUTY,
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
