<?php

namespace App\Models;

use App\Enums\SubscriptionType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    public function formattedStartsAt(): string
    {
        return $this->starts_at->translatedFormat('d F Y');
    }

    public function formattedEndsAt(): string
    {
        return $this->ends_at->translatedFormat('d F Y');
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
