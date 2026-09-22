<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quotation extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SENT = 'sent';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CONVERTED = 'converted';

    public const DEFAULT_VALIDITY_DAYS = 15;

    protected $fillable = [
        'quotation_number', 'status', 'branch_id', 'sales_employee_id', 'valid_until', 'customer_id', 'customer_vehicle_id',
        'customer_name', 'customer_email', 'customer_phone', 'customer_document',
        'customer_city', 'customer_address', 'customer_notes', 'vehicle_brand_id',
        'vehicle_model_id', 'vehicle_version_id', 'vehicle_year', 'vehicle_plate',
        'vehicle_vin', 'vehicle_color', 'vehicle_notes', 'vehicle_brand_name',
        'vehicle_model_name', 'vehicle_version_name', 'subtotal', 'discount_total',
        'total', 'notes', 'created_by', 'updated_by', 'converted_by', 'converted_at',
        'order_id',
    ];

    protected $casts = [
        'branch_id' => 'integer', 'sales_employee_id' => 'integer', 'valid_until' => 'date', 'subtotal' => 'integer', 'discount_total' => 'integer',
        'total' => 'integer', 'vehicle_year' => 'integer', 'converted_at' => 'datetime',
    ];

    public static function statuses(): array
    {
        return [self::STATUS_DRAFT, self::STATUS_SENT, self::STATUS_REJECTED, self::STATUS_EXPIRED, self::STATUS_CONVERTED];
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_SENT], true);
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(QuotationStatusHistory::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function salesEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'sales_employee_id');
    }

    public function customerVehicle(): BelongsTo
    {
        return $this->belongsTo(CustomerVehicle::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function converter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'converted_by');
    }
}
