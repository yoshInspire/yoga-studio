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
        return $user->subscriptions()
            ->forType($classType)
            ->active($on)
            ->orderBy('ends_at')
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

    public function deduct(Subscription $subscription, ?string $description = null, ?Carbon $usedAt = null): SubscriptionUsage
    {
        if (! $this->canDeduct($subscription)) {
            throw new InvalidArgumentException('В абонементе нет доступных занятий или срок действия истёк.');
        }

        $subscription->increment('sessions_used');

        return $subscription->usages()->create([
            'used_at' => $usedAt ?? now(),
            'description' => $description,
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
}
