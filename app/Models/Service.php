<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Service extends Model
{
    protected $fillable = [
        'service_category_id', 'name', 'slug', 'description', 'price', 'cost',
        'estimated_duration_minutes', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'price' => 'integer', 'cost' => 'integer', 'estimated_duration_minutes' => 'integer',
        'is_active' => 'boolean', 'sort_order' => 'integer',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'service_category_id');
    }

    public function serviceCategory(): BelongsTo
    {
        return $this->category();
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function quotationItems(): HasMany
    {
        return $this->hasMany(QuotationItem::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeCommerciallyAvailable(Builder $query): Builder
    {
        return $query->active()->whereHas('category', fn (Builder $category) => $category->active());
    }
}
