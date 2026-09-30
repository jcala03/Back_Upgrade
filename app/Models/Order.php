<?php

namespace App\Models;

use App\Support\Business\BusinessContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const ORIGIN_ECOMMERCE = 'ecommerce';

    public const ORIGIN_CRM = 'crm';

    public const PAYMENT_UNPAID = 'unpaid';

    public const PAYMENT_PARTIAL = 'partial';

    public const PAYMENT_PAID = 'paid';

    public const PAYMENT_REFUNDED = 'refunded';

    public const FULFILLMENT_SHIPPING = 'shipping';

    public const FULFILLMENT_PICKUP = 'pickup';

    protected $attributes = [
        'charges_total' => 0,
        'currency' => BusinessContext::CURRENCY,
        'fulfillment_type' => self::FULFILLMENT_PICKUP,
    ];

    protected $fillable = [
        'order_number', 'public_token_hash', 'checkout_idempotency_key', 'checkout_idempotency_fingerprint', 'quotation_id', 'branch_id', 'sales_employee_id', 'origin', 'user_id', 'customer_id', 'customer_vehicle_id', 'created_by', 'cancelled_by',
        'customer_name', 'customer_email', 'customer_phone', 'customer_document',
        'customer_city', 'customer_address', 'customer_notes', 'subtotal',
        'discount_total', 'charges_total', 'currency', 'fulfillment_type', 'total', 'status', 'payment_status', 'payment_provider',
        'payment_reference', 'payment_transaction_id', 'vehicle_brand_id',
        'vehicle_model_id', 'vehicle_version_id', 'vehicle_year', 'vehicle_plate',
        'vehicle_vin', 'vehicle_color', 'vehicle_notes', 'vehicle_brand_name',
        'vehicle_model_name', 'vehicle_version_name', 'confirmed_at', 'completed_at',
        'cancelled_at', 'stock_committed_at', 'stock_reverted_at', 'cancel_reason', 'stock_reservation_expires_at',
    ];

    protected $casts = [
        'branch_id' => 'integer', 'sales_employee_id' => 'integer',
        'subtotal' => 'integer', 'discount_total' => 'integer', 'charges_total' => 'integer', 'total' => 'integer',
        'vehicle_year' => 'integer', 'confirmed_at' => 'datetime',
        'completed_at' => 'datetime', 'cancelled_at' => 'datetime',
        'stock_committed_at' => 'datetime', 'stock_reverted_at' => 'datetime',
        'stock_reservation_expires_at' => 'datetime',
    ];

    public static function statuses(): array
    {
        return [self::STATUS_PENDING, self::STATUS_CONFIRMED, self::STATUS_COMPLETED, self::STATUS_CANCELLED];
    }

    public static function origins(): array
    {
        return [self::ORIGIN_ECOMMERCE, self::ORIGIN_CRM];
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function reconciliationReviews(): HasMany
    {
        return $this->hasMany(PaymentReconciliationReview::class);
    }

    public function canRetryPayment(): bool
    {
        return $this->origin === self::ORIGIN_ECOMMERCE
            && $this->status === self::STATUS_CONFIRMED
            && $this->payment_status === self::PAYMENT_UNPAID
            && $this->branch_id !== null
            && $this->stock_committed_at !== null
            && $this->stock_reverted_at === null
            && $this->stock_reservation_expires_at?->isFuture()
            && ! $this->payments()->where(function ($query) {
                $query->where('status', Payment::STATUS_COMPLETED)
                    ->orWhereNotNull('reconciliation_required_at')
                    ->orWhereHas('reconciliationReviews', fn ($reviews) => $reviews
                        ->where('state', '!=', PaymentReconciliationReview::STATE_RESOLVED));
            })->exists();
    }

    public function charges(): HasMany
    {
        return $this->hasMany(OrderCharge::class);
    }

    public function shippingAddress(): HasOne
    {
        return $this->hasOne(OrderAddress::class)->where('type', OrderAddress::TYPE_SHIPPING);
    }

    public static function fulfillmentTypes(): array
    {
        return [self::FULFILLMENT_SHIPPING, self::FULFILLMENT_PICKUP];
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class);
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(EmployeeCommission::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function vehicleBrand(): BelongsTo
    {
        return $this->belongsTo(VehicleBrand::class);
    }

    public function vehicleModel(): BelongsTo
    {
        return $this->belongsTo(VehicleModel::class);
    }

    public function vehicleVersion(): BelongsTo
    {
        return $this->belongsTo(VehicleVersion::class);
    }
}
