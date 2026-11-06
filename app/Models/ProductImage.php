<?php

namespace App\Models;

use App\Support\PublicStorageUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductImage extends Model
{
    protected $fillable = ['product_id', 'path', 'is_primary', 'sort_order'];

    protected $casts = ['is_primary' => 'boolean', 'sort_order' => 'integer'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function imageUrl(): string
    {
        return PublicStorageUrl::image($this->path) ?? '';
    }
}
