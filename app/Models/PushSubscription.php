<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PushSubscription extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['endpoint', 'p256dh', 'auth', 'user_id'];

    protected function casts(): array
    {
        return [
            'endpoint' => 'encrypted', 'p256dh' => 'encrypted', 'auth' => 'encrypted',
            'is_active' => 'boolean', 'expires_at' => 'datetime', 'last_sent_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
