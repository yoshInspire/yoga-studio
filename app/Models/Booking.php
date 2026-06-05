<?php

namespace App\Models;

use App\Enums\BookingStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'class_session_id',
    'subscription_id',
    'subscription_usage_id',
    'status',
    'cancellation_reason',
    'cancelled_at',
])]
class Booking extends Model
{
    protected function casts(): array
    {
        return [
            'status' => BookingStatus::class,
            'cancelled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function classSession(): BelongsTo
    {
        return $this->belongsTo(ClassSession::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function subscriptionUsage(): BelongsTo
    {
        return $this->belongsTo(SubscriptionUsage::class);
    }

    public function isConfirmed(): bool
    {
        return $this->status === BookingStatus::Confirmed;
    }

    public function canBeCancelledByClient(): bool
    {
        if (! $this->isConfirmed()) {
            return false;
        }

        $deadlineHours = (int) config('studio.cancellation_deadline_hours');

        return $this->classSession->starts_at->gt(now()->addHours($deadlineHours));
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('status', BookingStatus::Confirmed)
            ->whereHas('classSession', fn (Builder $q) => $q->where('starts_at', '>=', now()));
    }
}
