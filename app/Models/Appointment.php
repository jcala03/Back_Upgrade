<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Appointment extends Model
{
    public const SOURCE_CRM = 'crm';

    public const SOURCE_PUBLIC_WEB = 'public_web';

    public const STATUS_REQUESTED = 'requested';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_NO_SHOW = 'no_show';

    protected $fillable = ['customer_id', 'customer_vehicle_id', 'service_id', 'responsible_employee_id', 'branch_id', 'source', 'status',
        'title', 'description', 'contact_name', 'contact_phone', 'contact_email', 'vehicle_description', 'service_name',
        'starts_at', 'ends_at', 'created_by', 'updated_by', 'cancelled_by', 'cancelled_at', 'cancellation_reason',
        'availability_override', 'availability_override_reason', 'availability_overridden_by', 'availability_overridden_at'];

    protected $casts = ['branch_id' => 'integer', 'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime',
        'availability_override' => 'boolean', 'availability_overridden_at' => 'immutable_datetime'];

    public static function sources(): array
    {
        return [self::SOURCE_CRM, self::SOURCE_PUBLIC_WEB];
    }

    public static function statuses(): array
    {
        return [self::STATUS_REQUESTED, self::STATUS_CONFIRMED, self::STATUS_IN_PROGRESS, self::STATUS_COMPLETED, self::STATUS_CANCELLED, self::STATUS_NO_SHOW];
    }

    public static function initialStatuses(): array
    {
        return [self::STATUS_REQUESTED, self::STATUS_CONFIRMED];
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_CANCELLED, self::STATUS_NO_SHOW], true);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function customerVehicle(): BelongsTo
    {
        return $this->belongsTo(CustomerVehicle::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function responsibleEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'responsible_employee_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function availabilityOverrider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'availability_overridden_by');
    }

    public function scopeBlockingAvailability(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_CONFIRMED, self::STATUS_IN_PROGRESS]);
    }
}
