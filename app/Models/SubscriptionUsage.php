<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'subscription_id',
    'used_at',
    'description',
    'sessions_spent',
])]
class SubscriptionUsage extends Model
{
    protected function casts(): array
    {
        return [
            'used_at' => 'datetime',
            'sessions_spent' => 'integer',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
