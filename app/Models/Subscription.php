<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use App\Enums\BookingStatus;
use App\Enums\SubscriptionType;
use App\Support\RussianDate;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

#[Fillable([
    'user_id',
    'type',
    'sessions_total',
    'sessions_used',
    'sessions_per_day',
    'purchased_at',
    'starts_at',
    'ends_at',
    'admin_note',
    'low_sessions_notified_at',
    'expiring_notified_at',
])]
class Subscription extends Model
{
    protected function casts(): array
    {
        return [
            'type' => SubscriptionType::class,
            'sessions_total' => 'integer',
            'sessions_used' => 'integer',
            'sessions_per_day' => 'integer',
            'purchased_at' => 'date',
            'starts_at' => 'date',
            'ends_at' => 'date',
            'low_sessions_notified_at' => 'datetime',
            'expiring_notified_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function usages(): HasMany
    {
        return $this->hasMany(SubscriptionUsage::class);
    }

    /**
     * Позиция платежа, которой оплачен абонемент, — источник цены для отчёта.
     *
     * Есть не у всех: абонементы из старой системы и выданные администратором
     * вручную через сайт не оплачивались, и цены у них нет.
     */
    public function paymentItem(): HasOne
    {
        return $this->hasOne(PaymentItem::class);
    }

    /**
     * Цена покупки в рублях. null — абонемент не покупался онлайн.
     */
    public function price(): ?int
    {
        $price = $this->paymentItem?->price;

        return $price !== null ? (int) $price : null;
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * Участники семьи, которым владелец открыл этот абонемент.
     *
     * Строка здесь — единственное, что даёт чужому человеку записываться по
     * абонементу: членство в семье само по себе доступа не открывает.
     */
    public function sharedWith(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'subscription_shares')->withTimestamps();
    }

    /**
     * Можно ли открыть абонемент семье.
     *
     * Групповые и индивидуальные — да. «Двойной» — нет: там оплачивается день
     * одного человека, и второй участник в тот же день сломал бы дневной учёт.
     * Мероприятия вне абонемента — разовые, делить там нечего.
     */
    public function isShareable(): bool
    {
        return in_array($this->type, [SubscriptionType::Group, SubscriptionType::Individual], true)
            && ! $this->isDoublePerDay();
    }

    /** Абонемент принадлежит этому человеку или открыт ему владельцем. */
    public function isUsableBy(User $user): bool
    {
        if ($this->user_id === $user->id) {
            return true;
        }

        return $this->isShareable()
            && $this->sharedWith()->whereKey($user->id)->exists();
    }

    public function sessionsRemaining(): int
    {
        return max(0, $this->sessions_total - $this->sessions_used);
    }

    /**
     * Сколько занятий списывается за один день использования.
     * Для «двойного» абонемента — 2 (списываются всегда оба, даже если
     * клиент пришёл на одно занятие в этот день).
     */
    public function sessionsPerDay(): int
    {
        return max(1, (int) $this->sessions_per_day);
    }

    public function isDoublePerDay(): bool
    {
        return $this->sessionsPerDay() > 1;
    }

    /**
     * Сколько полных дней осталось до окончания действия (от сегодня).
     * Отрицательное значение — абонемент уже просрочен.
     */
    public function daysUntilEnd(?Carbon $on = null): int
    {
        $on ??= now()->startOfDay();

        return (int) $on->diffInDays($this->ends_at->copy()->startOfDay(), false);
    }

    public function isActive(?Carbon $on = null): bool
    {
        $on = ($on ?? now())->copy()->startOfDay();

        return $this->starts_at->lte($on)
            && $this->ends_at->gte($on)
            && $this->sessionsRemaining() > 0;
    }

    /**
     * Сколько занятий уже обещано записям, которые ещё ждут списания.
     *
     * Запись по абонементу, который ещё не начал действовать, создаётся без
     * списания — оно случится в день начала, — и `sessions_used` её не видит.
     * Без этого счёта две записи, сделанные заранее, уходили на один разовый
     * абонемент: первую списывали в день начала, вторая навсегда оставалась
     * «Без списания», а следующий абонемент клиента стоял нетронутым.
     *
     * Неявку не считаем: занятие по ней вернули. Для «двойного» абонемента
     * считаем дни, а не записи, и пропускаем уже оплаченные дни.
     */
    public function pendingSessions(?int $excludeBookingId = null): int
    {
        return $this->pendingSessionsAmong($this->bookingsHoldingSessions($excludeBookingId));
    }

    public function sessionsAvailableForBooking(?int $excludeBookingId = null): int
    {
        return max(0, $this->sessionsRemaining() - $this->pendingSessions($excludeBookingId));
    }

    /**
     * Хватит ли абонемента ещё на одну запись в этот день — с учётом записей,
     * которые ждут списания (см. `pendingSessions()`).
     *
     * У «двойного» абонемента второе занятие дня ничего не стоит: день уже
     * оплачен или обещан другой записи.
     */
    public function canTakeBookingOn(Carbon $day, ?int $excludeBookingId = null): bool
    {
        $bookings = $this->bookingsHoldingSessions($excludeBookingId);

        if ($this->isDoublePerDay()
            && $bookings->contains(fn (Booking $b) => $b->classSession?->starts_at?->isSameDay($day))) {
            return true;
        }

        return $this->sessionsRemaining() - $this->pendingSessionsAmong($bookings) >= $this->sessionsPerDay();
    }

    /**
     * @return Collection<int, Booking>
     */
    private function bookingsHoldingSessions(?int $excludeBookingId): Collection
    {
        return $this->bookings()
            ->where('status', BookingStatus::Confirmed)
            ->where(fn ($q) => $q->whereNull('attendance_status')
                ->orWhere('attendance_status', '!=', AttendanceStatus::NoShow))
            ->when($excludeBookingId, fn ($q) => $q->where('id', '!=', $excludeBookingId))
            ->with('classSession')
            ->get();
    }

    /**
     * @param  Collection<int, Booking>  $bookings
     */
    private function pendingSessionsAmong(Collection $bookings): int
    {
        $pending = $bookings->whereNull('subscription_usage_id');

        if (! $this->isDoublePerDay()) {
            return $pending->count();
        }

        $day = fn (Booking $b) => $b->classSession?->starts_at?->toDateString();
        $chargedDays = $bookings->whereNotNull('subscription_usage_id')->map($day)->filter()->unique();

        return $pending->map($day)->filter()->unique()->diff($chargedDays)->count() * $this->sessionsPerDay();
    }

    /**
     * Абонемент уже начал действовать (дата начала наступила).
     */
    public function hasStarted(?Carbon $on = null): bool
    {
        $on = ($on ?? now())->copy()->startOfDay();

        return $this->starts_at->lte($on);
    }

    public function formattedStartsAt(): string
    {
        return RussianDate::dayMonthYear($this->starts_at);
    }

    public function formattedEndsAt(): string
    {
        return RussianDate::dayMonthYear($this->ends_at);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query, ?Carbon $on = null): Builder
    {
        $on = ($on ?? now())->copy()->startOfDay();

        return $query
            ->whereDate('starts_at', '<=', $on)
            ->whereDate('ends_at', '>=', $on)
            ->whereColumn('sessions_used', '<', 'sessions_total');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeForType(Builder $query, SubscriptionType $type): Builder
    {
        return $query->where('type', $type->value);
    }
}
