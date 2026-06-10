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

        $deadlineHours = $this->cancellationDeadlineHours();

        return $this->classSession->starts_at->gt(now()->addHours($deadlineHours));
    }

    /**
     * За сколько часов до начала клиент ещё может отменить запись.
     * Зависит от времени занятия: до 12:00 — раньше, с 12:00 — позже.
     */
    public function cancellationDeadlineHours(): int
    {
        $config = config('studio.cancellation');

        if (! is_array($config)) {
            return (int) config('studio.cancellation_deadline_hours', 4);
        }

        $noonHour = (int) ($config['noon_hour'] ?? 12);
        $isMorning = (int) $this->classSession->starts_at->format('H') < $noonHour;

        return (int) ($isMorning ? ($config['morning_hours'] ?? 14) : ($config['day_hours'] ?? 4));
    }

    /**
     * Текст для клиента, почему отменить запись уже нельзя.
     */
    public function cancellationBlockedMessage(): string
    {
        return $this->deadlineBlockedMessage('отменить');
    }

    public function canBeRescheduledByClient(): bool
    {
        return $this->canBeCancelledByClient();
    }

    public function rescheduleBlockedMessage(): string
    {
        return $this->deadlineBlockedMessage('перенести');
    }

    private function deadlineBlockedMessage(string $action): string
    {
        $hours = $this->cancellationDeadlineHours();

        return 'Вы не можете '.$action.' запись, так как до начала занятия осталось менее '
            .$hours.' '.($hours === 1 ? 'часа' : 'часов').'.';
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
