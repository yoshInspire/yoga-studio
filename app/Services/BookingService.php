<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\ClassSessionStatus;
use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class BookingService
{
    public function __construct(
        private SubscriptionService $subscriptions,
    ) {}

    public function book(User $user, ClassSession $session, ?Subscription $subscription = null): Booking
    {
        return DB::transaction(function () use ($user, $session, $subscription) {
            $session = ClassSession::query()->lockForUpdate()->findOrFail($session->id);

            $this->assertCanBook($user, $session);

            $subscription ??= $this->subscriptions->findUsableForUser($user, $session->type, $session->starts_at);

            // Второе занятие в один день по «двойному» абонементу: активный
            // абонемент уже может быть исчерпан (списаны оба занятия), но день
            // оплачен — позволяем записаться на второе занятие без доплаты.
            $subscription ??= $this->findChargedDoubleSubscriptionForDay($user, $session);

            if ($subscription === null) {
                throw new InvalidArgumentException('Нет подходящего абонемента с доступными занятиями.');
            }

            if (! $this->subscriptions->typesMatch($subscription->type, $session->type)) {
                throw new InvalidArgumentException('Тип абонемента не подходит для этого занятия.');
            }

            if ($session->confirmedCount() >= $session->capacity) {
                throw new InvalidArgumentException('На занятии не осталось свободных мест.');
            }

            $usageId = $this->chargeForBooking($user, $session, $subscription);

            return Booking::query()->create([
                'user_id' => $user->id,
                'class_session_id' => $session->id,
                'subscription_id' => $subscription->id,
                'subscription_usage_id' => $usageId,
                'status' => BookingStatus::Confirmed,
            ]);
        });
    }

    /**
     * Списать занятия за бронь и вернуть id записи об использовании
     * (или null, если списывать нечего — например, это второе занятие в день
     * по «двойному» абонементу, где день уже оплачен).
     */
    private function chargeForBooking(User $user, ClassSession $session, Subscription $subscription): ?int
    {
        $perDay = $subscription->sessionsPerDay();
        $description = $session->title.' · '.$session->starts_at->format('d.m.Y H:i');

        if ($perDay > 1) {
            // Двойной абонемент: за один день использования списываются оба
            // занятия. Если в этот день по этому абонементу уже есть бронь,
            // держащая списание, то второе занятие не списываем повторно.
            $alreadyCharged = Booking::query()
                ->where('user_id', $user->id)
                ->where('subscription_id', $subscription->id)
                ->where('status', BookingStatus::Confirmed)
                ->whereNotNull('subscription_usage_id')
                ->whereHas('classSession', fn ($q) => $q->whereDate('starts_at', $session->starts_at->toDateString()))
                ->exists();

            if ($alreadyCharged) {
                return null;
            }
        }

        $usage = $this->subscriptions->deduct(
            $subscription,
            $description,
            $session->starts_at,
            $perDay,
        );

        return $usage->id;
    }

    /**
     * Найти «двойной» абонемент, по которому в этот день уже оплачено занятие,
     * чтобы записать клиента на второе занятие того же дня без повторного списания.
     */
    private function findChargedDoubleSubscriptionForDay(User $user, ClassSession $session): ?Subscription
    {
        $dayBooking = Booking::query()
            ->where('user_id', $user->id)
            ->where('status', BookingStatus::Confirmed)
            ->whereNotNull('subscription_usage_id')
            ->whereHas('classSession', fn ($q) => $q->whereDate('starts_at', $session->starts_at->toDateString()))
            ->whereHas('subscription', fn ($q) => $q->where('sessions_per_day', '>', 1)->forType($session->type))
            ->with('subscription')
            ->first();

        return $dayBooking?->subscription;
    }

    /**
     * Освободить списание при отмене брони.
     *
     * Для обычного абонемента — просто возвращаем занятие. Для «двойного»
     * абонемента день оплачен целиком (2 занятия), даже если клиент пришёл на
     * одно: если в этот день есть другая активная бронь по тому же абонементу,
     * возврат не делаем, а переносим списание на неё. Возврат происходит, только
     * когда в этот день не остаётся ни одной активной брони.
     */
    private function releaseBooking(Booking $booking): void
    {
        $usage = $booking->subscriptionUsage;

        if ($usage === null) {
            return;
        }

        $subscription = $usage->subscription;

        if ($subscription !== null && $subscription->isDoublePerDay()) {
            $other = Booking::query()
                ->where('id', '!=', $booking->id)
                ->where('subscription_id', $subscription->id)
                ->where('status', BookingStatus::Confirmed)
                ->whereNull('subscription_usage_id')
                ->whereHas('classSession', fn ($q) => $q->whereDate('starts_at', $usage->used_at->toDateString()))
                ->first();

            if ($other !== null) {
                $other->update(['subscription_usage_id' => $usage->id]);

                return;
            }
        }

        $this->subscriptions->refundUsage($usage);
    }

    public function cancelByClient(Booking $booking): Booking
    {
        if ($booking->user_id !== auth()->id()) {
            throw new InvalidArgumentException('Нельзя отменить чужую запись.');
        }

        if (! $booking->canBeCancelledByClient()) {
            throw new InvalidArgumentException($booking->cancellationBlockedMessage());
        }

        return DB::transaction(function () use ($booking) {
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);

            if (! $booking->isConfirmed()) {
                throw new InvalidArgumentException('Запись уже отменена.');
            }

            $this->releaseBooking($booking);

            $booking->update([
                'status' => BookingStatus::CancelledByClient,
                'cancelled_at' => now(),
                'subscription_usage_id' => null,
            ]);

            return $booking->refresh();
        });
    }

    public function cancelClass(ClassSession $session, string $reason, bool $refundClients = true): ClassSession
    {
        return DB::transaction(function () use ($session, $reason, $refundClients) {
            $session = ClassSession::query()->lockForUpdate()->findOrFail($session->id);

            if ($session->isCancelled()) {
                throw new InvalidArgumentException('Занятие уже отменено.');
            }

            $session->update([
                'status' => ClassSessionStatus::Cancelled,
                'cancellation_reason' => $reason,
                'cancelled_at' => now(),
            ]);

            $session->bookings()
                ->where('status', BookingStatus::Confirmed)
                ->each(function (Booking $booking) use ($reason, $refundClients) {
                    if ($refundClients) {
                        $this->releaseBooking($booking);
                    }

                    $booking->update([
                        'status' => BookingStatus::ClassCancelled,
                        'cancellation_reason' => $reason,
                        'cancelled_at' => now(),
                        'subscription_usage_id' => null,
                    ]);
                });

            return $session->refresh();
        });
    }

    public function cancelByAdmin(Booking $booking, ?string $reason = null, bool $refund = true): Booking
    {
        return DB::transaction(function () use ($booking, $reason, $refund) {
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);

            if (! $booking->isConfirmed()) {
                throw new InvalidArgumentException('Запись уже отменена.');
            }

            if ($refund) {
                $this->releaseBooking($booking);
            }

            $booking->update([
                'status' => BookingStatus::CancelledByAdmin,
                'cancellation_reason' => $reason,
                'cancelled_at' => now(),
                'subscription_usage_id' => null,
            ]);

            return $booking->refresh();
        });
    }

    protected function assertCanBook(User $user, ClassSession $session): void
    {
        if (! $session->isBookable()) {
            throw new InvalidArgumentException('Запись на это занятие недоступна.');
        }

        if (Booking::query()
            ->where('user_id', $user->id)
            ->where('class_session_id', $session->id)
            ->exists()) {
            throw new InvalidArgumentException('Вы уже записаны на это занятие.');
        }

        $dayBookings = Booking::query()
            ->where('user_id', $user->id)
            ->where('status', BookingStatus::Confirmed)
            ->whereHas('classSession', fn ($q) => $q->whereDate('starts_at', $session->starts_at->toDateString()))
            ->count();

        if ($dayBookings >= (int) config('studio.max_bookings_per_day')) {
            throw new InvalidArgumentException('В один день можно записаться максимум на '.config('studio.max_bookings_per_day').' занятия.');
        }
    }

    public function weekStart(?string $weekParam = null): Carbon
    {
        if ($weekParam) {
            return Carbon::parse($weekParam)->startOfWeek(Carbon::MONDAY);
        }

        return now()->startOfWeek(Carbon::MONDAY);
    }

    /**
     * @return array<int, array{key: string, name: string, date: string, slots: list<array>}>
     */
    public function buildWeekSchedule(Carbon $weekStart, ?User $viewer = null): array
    {
        $sessionsQuery = ClassSession::query()
            ->inWeek($weekStart)
            ->with(['trainer', 'direction'])
            ->withCount(['bookings as taken' => fn ($q) => $q->where('status', BookingStatus::Confirmed)])
            ->orderBy('starts_at');

        if ($viewer) {
            $sessionsQuery->with([
                'bookings' => fn ($q) => $q
                    ->where('user_id', $viewer->id)
                    ->where('status', BookingStatus::Confirmed),
            ]);
        }

        $sessions = $sessionsQuery
            ->get()
            ->groupBy(fn (ClassSession $s) => $s->starts_at->toDateString());

        $dayNames = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
        $dayKeys = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
        $week = [];

        for ($i = 0; $i < 7; $i++) {
            $date = $weekStart->copy()->addDays($i);
            $dateKey = $date->toDateString();
            $daySessions = $sessions->get($dateKey, collect());

            $slots = $daySessions->map(function (ClassSession $session) use ($viewer) {
                $userBooked = $viewer
                    ? $session->bookings->isNotEmpty()
                    : false;

                return [
                    'id' => $session->id,
                    'time' => $session->formattedTime(),
                    'title' => $session->title,
                    'direction' => $session->direction?->title,
                    'topic' => $session->topic,
                    'trainer' => $session->trainerName(),
                    'type' => $session->type->badgeClass(),
                    'taken' => (int) $session->taken,
                    'total' => $session->capacity,
                    'status' => $session->slotStatus(),
                    'reason' => $session->cancellation_reason,
                    'bookable' => $session->isBookable() && ! $userBooked,
                    'user_booked' => $userBooked,
                ];
            })->values()->all();

            $week[] = [
                'key' => $dayKeys[$i],
                'name' => $dayNames[$i],
                'date' => $date->translatedFormat('j F'),
                'slots' => $slots,
            ];
        }

        return $week;
    }
}
