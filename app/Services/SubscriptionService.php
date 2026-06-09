<?php

namespace App\Services;

use App\Enums\SubscriptionType;
use App\Models\Subscription;
use App\Models\SubscriptionUsage;
use App\Models\User;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class SubscriptionService
{
    public function findUsableForUser(User $user, SubscriptionType $classType, ?Carbon $on = null): ?Subscription
    {
        // Историчность: списываем из абонемента, приобретённого раньше всех
        // (первичный), затем — по сроку окончания.
        return $user->subscriptions()
            ->forType($classType)
            ->active($on)
            ->orderBy('purchased_at')
            ->orderBy('starts_at')
            ->orderBy('ends_at')
            ->orderBy('id')
            ->first();
    }

    /**
     * @return list<Subscription>
     */
    public function activeForUser(User $user, ?Carbon $on = null): array
    {
        return $user->subscriptions()
            ->active($on)
            ->orderBy('type')
            ->orderBy('ends_at')
            ->get()
            ->all();
    }

    public function canDeduct(Subscription $subscription, ?Carbon $on = null): bool
    {
        return $subscription->isActive($on);
    }

    public function deduct(Subscription $subscription, ?string $description = null, ?Carbon $usedAt = null, int $count = 1): SubscriptionUsage
    {
        if ($count < 1) {
            throw new InvalidArgumentException('Количество списываемых занятий должно быть не меньше 1.');
        }

        if (! $this->canDeduct($subscription)) {
            throw new InvalidArgumentException('В абонементе нет доступных занятий или срок действия истёк.');
        }

        if ($subscription->sessionsRemaining() < $count) {
            throw new InvalidArgumentException('В абонементе недостаточно занятий для списания.');
        }

        $subscription->increment('sessions_used', $count);

        return $subscription->usages()->create([
            'used_at' => $usedAt ?? now(),
            'description' => $description,
            'sessions_spent' => $count,
        ]);
    }

    public function extend(Subscription $subscription, Carbon $newEndsAt): Subscription
    {
        if ($newEndsAt->lt($subscription->starts_at)) {
            throw new InvalidArgumentException('Дата окончания не может быть раньше даты начала.');
        }

        $subscription->update(['ends_at' => $newEndsAt]);

        return $subscription->refresh();
    }

    public function changeStartDate(Subscription $subscription, Carbon $newStartsAt): Subscription
    {
        if ($newStartsAt->gt($subscription->ends_at)) {
            throw new InvalidArgumentException('Дата начала не может быть позже даты окончания.');
        }

        $subscription->update(['starts_at' => $newStartsAt]);

        return $subscription->refresh();
    }

    public function addSessions(Subscription $subscription, int $count): Subscription
    {
        if ($count < 1) {
            throw new InvalidArgumentException('Количество занятий должно быть не меньше 1.');
        }

        $subscription->increment('sessions_total', $count);

        return $subscription->refresh();
    }

    public function typesMatch(SubscriptionType $subscriptionType, SubscriptionType $classType): bool
    {
        return $subscriptionType->isCompatibleWith($classType);
    }

    public function refundUsage(SubscriptionUsage $usage): void
    {
        $subscription = $usage->subscription;
        $spent = max(1, (int) $usage->sessions_spent);

        $subscription->update([
            'sessions_used' => max(0, $subscription->sessions_used - $spent),
        ]);

        $usage->delete();
    }

    /**
     * Вернуть администратором одно списанное занятие в абонемент
     * (например, клиент не пришёл по уважительной причине, а занятие уже списали).
     */
    public function returnSession(Subscription $subscription, ?string $reason = null): Subscription
    {
        if ($subscription->sessions_used < 1) {
            throw new InvalidArgumentException('В абонементе нет списанных занятий для возврата.');
        }

        $latestUsage = $subscription->usages()->latest('used_at')->first();
        $spent = $latestUsage !== null ? max(1, (int) $latestUsage->sessions_spent) : 1;

        $subscription->update([
            'sessions_used' => max(0, $subscription->sessions_used - $spent),
        ]);

        if ($latestUsage !== null) {
            $latestUsage->delete();
        }

        if ($reason !== null && $reason !== '') {
            $note = trim(($subscription->admin_note ? $subscription->admin_note."\n" : '')
                .now()->format('d.m.Y H:i').' — возврат занятия: '.$reason);
            $subscription->update(['admin_note' => $note]);
        }

        return $subscription->refresh();
    }

    public function createFromPurchase(
        User $user,
        SubscriptionType $type,
        int $sessionsTotal,
        Carbon $startsAt,
        Carbon $purchasedAt,
        int $validityDays,
        ?string $adminNote = null,
        int $sessionsPerDay = 1,
    ): Subscription {
        if ($sessionsTotal < 1) {
            throw new InvalidArgumentException('Количество занятий должно быть не меньше 1.');
        }

        $startsAt = $startsAt->copy()->startOfDay();
        $endsAt = $startsAt->copy()->addDays($validityDays);

        return Subscription::query()->create([
            'user_id' => $user->id,
            'type' => $type,
            'sessions_total' => $sessionsTotal,
            'sessions_used' => 0,
            'sessions_per_day' => max(1, $sessionsPerDay),
            'purchased_at' => $purchasedAt->toDateString(),
            'starts_at' => $startsAt->toDateString(),
            'ends_at' => $endsAt->toDateString(),
            'admin_note' => $adminNote,
        ]);
    }
}
