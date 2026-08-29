<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Subscription;
use Illuminate\Support\Collection;

class SubscriptionBalanceService
{
    /**
     * @return array{
     *     sessions_total: int,
     *     sessions_reserved: int,
     *     sessions_consumed: int,
     *     sessions_remaining: int,
     * }
     */
    public function breakdown(Subscription $subscription): array
    {
        $chargedReserved = $this->chargedReservedSessions($subscription);

        return [
            'sessions_total' => $subscription->sessions_total,
            'sessions_reserved' => $this->reservedSessions($subscription),
            'sessions_consumed' => max(0, $subscription->sessions_used - $chargedReserved),
            'sessions_remaining' => $subscription->sessionsRemaining(),
        ];
    }

    /**
     * @param  Collection<int, Subscription>|list<Subscription>  $subscriptions
     * @return array<int, array{
     *     sessions_total: int,
     *     sessions_reserved: int,
     *     sessions_consumed: int,
     *     sessions_remaining: int,
     * }>
     */
    public function breakdownForMany(Collection $subscriptions): array
    {
        $result = [];

        foreach ($subscriptions as $subscription) {
            $result[$subscription->id] = $this->breakdown($subscription);
        }

        return $result;
    }

    public function reservedSessions(Subscription $subscription): int
    {
        $bookings = $this->expectedBookings($subscription);

        $reserved = 0;
        $countedUsageIds = [];

        foreach ($bookings as $booking) {
            if ($booking->subscription_usage_id !== null) {
                if (! in_array($booking->subscription_usage_id, $countedUsageIds, true)) {
                    $countedUsageIds[] = $booking->subscription_usage_id;
                    $reserved += $booking->subscriptionUsage?->sessions_spent ?? $subscription->sessionsPerDay();
                }

                continue;
            }

            if ($subscription->isDoublePerDay() && $this->hasChargedSiblingOnDay($bookings, $booking)) {
                continue;
            }

            $reserved += $subscription->sessionsPerDay();
        }

        return $reserved;
    }

    private function chargedReservedSessions(Subscription $subscription): int
    {
        $bookings = $this->expectedBookings($subscription);

        $reserved = 0;
        $countedUsageIds = [];

        foreach ($bookings as $booking) {
            if ($booking->subscription_usage_id === null) {
                continue;
            }

            if (! in_array($booking->subscription_usage_id, $countedUsageIds, true)) {
                $countedUsageIds[] = $booking->subscription_usage_id;
                $reserved += $booking->subscriptionUsage?->sessions_spent ?? $subscription->sessionsPerDay();
            }
        }

        return $reserved;
    }

    /**
     * Записи, под которые занятие ещё только держится в резерве.
     *
     * Резерв — это будущие занятия. Как только занятие началось, списание за
     * него потрачено, даже если посещение не отметили кнопкой «Был(а)»:
     * администратор часто просто записывает пришедшего клиента задним числом.
     * Иначе такое списание навсегда оставалось бы в «рез.» и не попадало в
     * «исп.» — остаток верный, а количество использованных не менялось.
     * Так же считает отчёт по абонементам (`ReportService::completedSessionsUsed()`).
     *
     * @return Collection<int, Booking>
     */
    private function expectedBookings(Subscription $subscription): Collection
    {
        return Booking::query()
            ->where('subscription_id', $subscription->id)
            ->where('status', BookingStatus::Confirmed)
            ->where(function ($query) {
                $query->where('attendance_status', AttendanceStatus::Expected)
                    ->orWhereNull('attendance_status');
            })
            ->whereHas('classSession', fn ($query) => $query->where('starts_at', '>', now()))
            ->with(['classSession', 'subscriptionUsage'])
            ->get();
    }

    /**
     * @param  Collection<int, Booking>  $bookings
     */
    private function hasChargedSiblingOnDay(Collection $bookings, Booking $booking): bool
    {
        $day = $booking->classSession?->starts_at?->toDateString();

        if ($day === null) {
            return false;
        }

        return $bookings->contains(function (Booking $other) use ($booking, $day) {
            return $other->id !== $booking->id
                && $other->subscription_usage_id !== null
                && $other->classSession?->starts_at?->toDateString() === $day;
        });
    }
}
