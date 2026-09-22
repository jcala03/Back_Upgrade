<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessSetting extends Model
{
    protected $fillable = [
        'business_name', 'legal_name', 'tax_id', 'phone', 'email', 'whatsapp',
        'address', 'city', 'quotation_validity_days', 'order_notification_email',
        'updated_by',
    ];

    protected $casts = [
        'quotation_validity_days' => 'integer',
    ];

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
