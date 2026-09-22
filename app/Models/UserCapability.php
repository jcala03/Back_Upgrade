<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserCapability extends Model
{
    public const QUOTATIONS_VIEW_OWN = 'quotations.view_own';

    public const QUOTATIONS_CREATE_OWN = 'quotations.create_own';

    public const QUOTATIONS_UPDATE_OWN = 'quotations.update_own';

    public const QUOTATIONS_SEND_OWN = 'quotations.send_own';

    public const QUOTATIONS_CONVERT_OWN = 'quotations.convert_own';

    public const ORDERS_VIEW_OWN = 'orders.view_own';

    public const ORDERS_CREATE_OWN = 'orders.create_own';

    public const ORDERS_CONFIRM_OWN = 'orders.confirm_own';

    public const ORDERS_COMPLETE_OWN = 'orders.complete_own';

    public const PAYMENTS_CREATE_OWN = 'payments.create_own';

    public const COMMISSIONS_VIEW_OWN = 'commissions.view_own';

    protected $fillable = [
        'user_id',
        'capability',
        'granted_by',
    ];

    public static function allowed(): array
    {
        return [
            self::QUOTATIONS_VIEW_OWN,
            self::QUOTATIONS_CREATE_OWN,
            self::QUOTATIONS_UPDATE_OWN,
            self::QUOTATIONS_SEND_OWN,
            self::QUOTATIONS_CONVERT_OWN,
            self::ORDERS_VIEW_OWN,
            self::ORDERS_CREATE_OWN,
            self::ORDERS_CONFIRM_OWN,
            self::ORDERS_COMPLETE_OWN,
            self::PAYMENTS_CREATE_OWN,
            self::COMMISSIONS_VIEW_OWN,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function granter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
