<?php

namespace App\Models;

use App\Enums\BookingStatus;
use App\Enums\ClassSessionStatus;
use App\Enums\SubscriptionType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

#[Fillable([
    'title',
    'direction_id',
    'topic',
    'description',
    'starts_at',
    'duration_minutes',
    'type',
    'capacity',
    'trainer_id',
    'status',
    'cancellation_reason',
    'cancelled_at',
])]
class ClassSession extends Model
{
    protected static function booted(): void
    {
        static::saving(function (ClassSession $session) {
            $session->title = $session->composeTitle();
        });
    }

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'duration_minutes' => 'integer',
            'type' => SubscriptionType::class,
            'capacity' => 'integer',
            'status' => ClassSessionStatus::class,
            'cancelled_at' => 'datetime',
        ];
    }

    public function direction(): BelongsTo
    {
        return $this->belongsTo(Direction::class);
    }

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'trainer_id');
    }

    public function composeTitle(): string
    {
        $directionTitle = $this->direction_id
            ? ($this->relationLoaded('direction')
                ? $this->direction?->title
                : Direction::query()->whereKey($this->direction_id)->value('title'))
            : null;

        $parts = array_values(array_filter([
            filled($directionTitle) ? $directionTitle : null,
            filled($this->topic) ? $this->topic : null,
        ]));

        if ($parts !== []) {
            return implode(' · ', $parts);
        }

        return $this->topic
            ?? $this->attributes['title']
            ?? $this->getRawOriginal('title')
            ?? 'Занятие';
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function confirmedBookings(): HasMany
    {
        return $this->bookings()->where('status', BookingStatus::Confirmed);
    }

    public function confirmedCount(): int
    {
        return $this->confirmedBookings()->count();
    }

    public function freeSeats(): int
    {
        return max(0, $this->capacity - $this->confirmedCount());
    }

    /**
     * Группа ещё не набралась, и занятие может быть отменено автоматически.
     * Нужно, чтобы напоминание накануне не обещало занятие как состоявшееся.
     */
    public function awaitsGroupFill(?int $confirmed = null): bool
    {
        $config = config('studio.auto_cancel');

        if (! ($config['enabled'] ?? true)) {
            return false;
        }

        if ($this->status !== ClassSessionStatus::Scheduled || $this->type !== SubscriptionType::Group) {
            return false;
        }

        $confirmed ??= $this->confirmedCount();

        return $confirmed < (int) ($config['min_group_size'] ?? 2);
    }

    public function isFull(): bool
    {
        return $this->freeSeats() === 0;
    }

    public function isCancelled(): bool
    {
        return $this->status === ClassSessionStatus::Cancelled;
    }

    public function isWithinBookingWindow(?Carbon $now = null): bool
    {
        $now ??= now();

        return $this->starts_at->gt($now)
            && $this->starts_at->lte($now->copy()->addDays((int) config('studio.booking_days_ahead')));
    }

    public function isBookable(?Carbon $now = null): bool
    {
        $now ??= now();

        return $this->status === ClassSessionStatus::Scheduled
            && ! $this->isFull()
            && $this->isWithinBookingWindow($now);
    }

    public function slotStatus(): string
    {
        if ($this->isCancelled()) {
            return 'cancelled';
        }

        if ($this->isFull()) {
            return 'full';
        }

        return 'open';
    }

    public function formattedTime(): string
    {
        return $this->starts_at->format('H:i');
    }

    public static function defaultDurationMinutesForType(SubscriptionType|string|null $type): int
    {
        $typeValue = $type instanceof SubscriptionType ? $type->value : ($type ?? 'default');
        $defaults = config('studio.default_class_duration_minutes', []);

        return (int) ($defaults[$typeValue] ?? $defaults['default'] ?? 90);
    }

    public function durationMinutes(): int
    {
        if ($this->duration_minutes !== null) {
            return (int) $this->duration_minutes;
        }

        return self::defaultDurationMinutesForType($this->type);
    }

    public function endsAt(): Carbon
    {
        return $this->starts_at->copy()->addMinutes($this->durationMinutes());
    }

    public function formattedTimeRange(): string
    {
        return $this->formattedTime().'–'.$this->endsAt()->format('H:i');
    }

    public function formattedDateTime(): string
    {
        return mb_ucfirst($this->starts_at->translatedFormat('l, d.m.Y, H:i'));
    }

    /**
     * За сколько часов до начала проверяется набор группы (контроль автоотмены).
     * Занятия до 12:00 проверяются раньше (раньше уведомляем), с 12:00 — позже.
     */
    public function autoCancelDeadlineHours(): int
    {
        $config = config('studio.auto_cancel');
        $noonHour = (int) ($config['noon_hour'] ?? 12);
        $isMorning = (int) $this->starts_at->format('H') < $noonHour;

        return (int) ($isMorning ? ($config['morning_hours'] ?? 15) : ($config['day_hours'] ?? 5));
    }

    /**
     * Момент, начиная с которого занятие может быть автоматически отменено
     * при недоборе группы.
     */
    public function autoCancelCheckpoint(): Carbon
    {
        return $this->starts_at->copy()->subHours($this->autoCancelDeadlineHours());
    }

    public function trainerName(): string
    {
        return $this->trainer?->shortName() ?? '—';
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeInWeek(Builder $query, Carbon $weekStart): Builder
    {
        return $query->inDateRange(
            $weekStart->copy()->startOfDay(),
            $weekStart->copy()->addDays(6)->endOfDay(),
        );
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeInDateRange(Builder $query, Carbon $from, Carbon $to): Builder
    {
        return $query->whereBetween('starts_at', [$from, $to]);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeVisibleInSchedule(Builder $query, ?Carbon $now = null): Builder
    {
        $now ??= now();
        $from = $now->copy()->startOfDay();
        $to = $now->copy()->addDays((int) config('studio.booking_days_ahead'))->endOfDay();

        return $query->whereBetween('starts_at', [$from, $to]);
    }
}
