<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

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

    /**
     * Запись, за которую списано. Есть не у всех: ручные списания из админки
     * и импорт из старой системы шли без брони. По ней видно, кто ходил по
     * абонементу семьи.
     */
    public function booking(): HasOne
    {
        return $this->hasOne(Booking::class);
    }
}
