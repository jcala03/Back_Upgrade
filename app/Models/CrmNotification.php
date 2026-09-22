<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmNotification extends Model
{
    public const TYPE_STOCK_LOW = 'stock_low';

    public const TYPE_STOCK_OUT = 'stock_out';

    public const TYPE_ORDER_ECOMMERCE_PENDING = 'order_ecommerce_pending';

    public const TYPE_PAYMENT_RECEIVED = 'payment_received';

    public const TYPE_PAYMENT_RECONCILIATION_REQUIRED = 'payment_reconciliation_required';

    public const TYPE_QUOTATION_EXPIRING = 'quotation_expiring';

    public const TYPE_QUOTATION_CONVERTED = 'quotation_converted';

    public const TYPE_TASK_ASSIGNED = 'task_assigned';

    public const TYPE_TASK_RESCHEDULED = 'task_rescheduled';

    public const TYPE_TASK_REASSIGNED = 'task_reassigned';

    public const TYPE_TASK_CANCELLED = 'task_cancelled';

    public const TYPE_APPOINTMENT_ASSIGNED = 'appointment_assigned';

    public const TYPE_APPOINTMENT_RESCHEDULED = 'appointment_rescheduled';

    public const TYPE_APPOINTMENT_REASSIGNED = 'appointment_reassigned';

    public const TYPE_APPOINTMENT_CANCELLED = 'appointment_cancelled';

    public const TYPE_GOAL_ASSIGNED = 'goal_assigned';

    public const TYPE_GOAL_UPDATED = 'goal_updated';

    public const TYPE_GOAL_REASSIGNED = 'goal_reassigned';

    public const TYPE_GOAL_CANCELLED = 'goal_cancelled';

    public const TYPE_COMMISSION_EARNED = 'commission_earned';

    public const SEVERITY_INFO = 'info';

    public const SEVERITY_SUCCESS = 'success';

    public const SEVERITY_WARNING = 'warning';

    public const SEVERITY_DANGER = 'danger';

    protected $fillable = [
        'user_id',
        'type',
        'severity',
        'title',
        'message',
        'data',
        'reference_type',
        'reference_id',
        'dedupe_key',
        'read_at',
    ];

    protected $hidden = ['user_id', 'dedupe_key', 'updated_at'];

    protected $casts = [
        'data' => 'array',
        'read_at' => 'datetime',
    ];

    public static function types(): array
    {
        return [
            self::TYPE_STOCK_LOW,
            self::TYPE_STOCK_OUT,
            self::TYPE_ORDER_ECOMMERCE_PENDING,
            self::TYPE_PAYMENT_RECEIVED,
            self::TYPE_PAYMENT_RECONCILIATION_REQUIRED,
            self::TYPE_QUOTATION_EXPIRING,
            self::TYPE_QUOTATION_CONVERTED,
            self::TYPE_TASK_ASSIGNED,
            self::TYPE_TASK_RESCHEDULED,
            self::TYPE_TASK_REASSIGNED,
            self::TYPE_TASK_CANCELLED,
            self::TYPE_APPOINTMENT_ASSIGNED,
            self::TYPE_APPOINTMENT_RESCHEDULED,
            self::TYPE_APPOINTMENT_REASSIGNED,
            self::TYPE_APPOINTMENT_CANCELLED,
            self::TYPE_GOAL_ASSIGNED,
            self::TYPE_GOAL_UPDATED,
            self::TYPE_GOAL_REASSIGNED,
            self::TYPE_GOAL_CANCELLED,
            self::TYPE_COMMISSION_EARNED,
        ];
    }

    public static function severities(): array
    {
        return [self::SEVERITY_INFO, self::SEVERITY_SUCCESS, self::SEVERITY_WARNING, self::SEVERITY_DANGER];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
