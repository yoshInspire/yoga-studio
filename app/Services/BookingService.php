<?php

namespace App\Services;

use App\Support\RussianDate;
use App\Enums\AttendanceStatus;
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
        private WelcomeMessageService $welcomeMessages,
    ) {}

    public function book(User $user, ClassSession $session, ?Subscription $subscription = null): Booking
    {
        return $this->bookInternal($user, $session, $subscription, forAdmin: false);
    }

    public function bookForAdmin(User $user, ClassSession $session, ?Subscription $subscription = null): Booking
    {
        return $this->bookInternal($user, $session, $subscription, forAdmin: true);
    }

    private function bookInternal(User $user, ClassSession $session, ?Subscription $subscription, bool $forAdmin): Booking
    {
        $booking = DB::transaction(function () use ($user, $session, $subscription, $forAdmin) {
            $session = ClassSession::query()->lockForUpdate()->findOrFail($session->id);

            $this->assertCanBook($user, $session, forAdmin: $forAdmin);

            if ($subscription !== null) {
                if ($subscription->user_id !== $user->id) {
                    throw new InvalidArgumentException('Выбранный абонемент принадлежит другому клиенту.');
                }

                if (! $this->subscriptions->typesMatch($subscription->type, $session->type)) {
                    throw new InvalidArgumentException('Тип абонемента не подходит для этого занятия.');
                }

                if (! $subscription->isActive($session->starts_at)) {
                    throw new InvalidArgumentException(
                        'Выбранный абонемент не действует на дату занятия или на нём не осталось занятий.',
                    );
                }
            }

            $subscription ??= $this->subscriptions->findUsableForUser($user, $session->type, $session->starts_at);

            // Второе занятие в один день по «двойному» абонементу: активный
            // абонемент уже может быть исчерпан (списаны оба занятия), но день
            // оплачен — позволяем записаться на второе занятие без доплаты.
            $subscription ??= $this->findChargedDoubleSubscriptionForDay($user, $session);

            if ($subscription === null) {
                $reason = $this->subscriptions->bookingUnavailableReason(
                    $user,
                    $session->type,
                    $session->starts_at,
                );

                throw new InvalidArgumentException(
                    $reason ?? 'Нет подходящего абонемента с доступными занятиями.',
                );
            }

            if ($session->confirmedCount() >= $session->capacity) {
                throw new InvalidArgumentException('На занятии не осталось свободных мест.');
            }

            $usageId = $this->chargeForBooking($user, $session, $subscription);

            // Запись на занятие уникальна по паре «клиент + занятие»: если клиент
            // раньше отписался, переиспользуем ту же строку, сбрасывая следы
            // прошлой отмены, — иначе повторная запись упирается в unique-индекс.
            return Booking::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'class_session_id' => $session->id,
                ],
                [
                    'subscription_id' => $subscription->id,
                    'subscription_usage_id' => $usageId,
                    'status' => BookingStatus::Confirmed,
                    'attendance_status' => AttendanceStatus::Expected,
                    'attended_at' => null,
                    'cancellation_reason' => null,
                    'cancelled_at' => null,
                ],
            );
        });

        // Памятка «К вашему визиту» — только после успешного коммита, иначе
        // при откате транзакции клиент получил бы письмо о несостоявшейся брони.
        // Отсюда покрыты все пути: сайт, приложение и запись администратором.
        $this->welcomeMessages->sendOnFirstBooking($user);

        return $booking;
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

        // Абонемент с будущей датой начала: запись можно создать заранее,
        // но списание откладываем до наступления даты начала.
        if (! $subscription->hasStarted()) {
            return null;
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
     * Списать занятие по брони, если абонемент уже начал действовать,
     * а списание было отложено при ранней записи.
     */
    public function chargePendingBooking(Booking $booking): bool
    {
        if (! $booking->isConfirmed() || $booking->subscription_usage_id !== null) {
            return false;
        }

        $subscription = $booking->subscription;
        $session = $booking->classSession;

        if ($subscription === null || $session === null || ! $subscription->hasStarted()) {
            return false;
        }

        if (! $subscription->isActive($session->starts_at)) {
            return false;
        }

        $usageId = $this->chargeForBooking($booking->user, $session, $subscription);

        if ($usageId === null) {
            return false;
        }

        $booking->update(['subscription_usage_id' => $usageId]);

        return true;
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

    public function rescheduleByClient(Booking $booking, ClassSession $newSession): Booking
    {
        if ($booking->user_id !== auth()->id()) {
            throw new InvalidArgumentException('Нельзя перенести чужое бронирование.');
        }

        if (! $booking->canBeRescheduledByClient()) {
            throw new InvalidArgumentException($booking->rescheduleBlockedMessage());
        }

        return DB::transaction(function () use ($booking, $newSession) {
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);
            $newSession = ClassSession::query()->lockForUpdate()->findOrFail($newSession->id);

            if (! $booking->isConfirmed()) {
                throw new InvalidArgumentException('Запись уже отменена.');
            }

            if ($booking->class_session_id === $newSession->id) {
                throw new InvalidArgumentException('Вы уже забронировали место на это занятие.');
            }

            $subscription = $booking->subscription;

            if ($subscription === null) {
                throw new InvalidArgumentException('Не удалось определить абонемент бронирования.');
            }

            if (! $this->subscriptions->typesMatch($subscription->type, $newSession->type)) {
                throw new InvalidArgumentException('Тип абонемента не подходит для этого занятия.');
            }

            $this->assertCanBook($booking->user, $newSession, excludeBookingId: $booking->id);

            if ($newSession->confirmedCount() >= $newSession->capacity) {
                throw new InvalidArgumentException('На занятии не осталось свободных мест.');
            }

            $this->releaseBooking($booking);

            $usageId = $this->chargeForBooking($booking->user, $newSession, $subscription);

            $booking->update([
                'class_session_id' => $newSession->id,
                'subscription_id' => $subscription->id,
                'subscription_usage_id' => $usageId,
            ]);

            return $booking->refresh();
        });
    }

    public function cancelByClient(Booking $booking): Booking
    {
        if ($booking->user_id !== auth()->id()) {
            throw new InvalidArgumentException('Нельзя отменить чужое бронирование.');
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

    public function markAttended(Booking $booking): Booking
    {
        return DB::transaction(function () use ($booking) {
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);

            if (! $booking->isConfirmed()) {
                throw new InvalidArgumentException('Запись отменена — отметить посещение нельзя.');
            }

            $booking->update([
                'attendance_status' => AttendanceStatus::Attended,
                'attended_at' => now(),
            ]);

            return $booking->refresh();
        });
    }

    /**
     * Клиент не пришёл: отмечаем и возвращаем списание на абонемент, если оно было.
     */
    public function markNoShow(Booking $booking): Booking
    {
        return DB::transaction(function () use ($booking) {
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);

            if (! $booking->isConfirmed()) {
                throw new InvalidArgumentException('Запись отменена — отметить неявку нельзя.');
            }

            if ($booking->isCharged()) {
                $this->releaseBooking($booking);
            }

            $booking->update([
                'subscription_usage_id' => null,
                'attendance_status' => AttendanceStatus::NoShow,
                'attended_at' => now(),
            ]);

            return $booking->refresh();
        });
    }

    protected function assertCanBook(User $user, ClassSession $session, ?int $excludeBookingId = null, bool $forAdmin = false): void
    {
        if ($forAdmin) {
            if ($session->status !== ClassSessionStatus::Scheduled) {
                throw new InvalidArgumentException('Занятие отменено или недоступно для бронирования.');
            }

            if ($session->isFull()) {
                throw new InvalidArgumentException('На занятии не осталось свободных мест.');
            }
        } elseif (! $session->isBookable()) {
            throw new InvalidArgumentException('Бронирование на это занятие недоступно.');
        }

        if (Booking::query()
            ->where('user_id', $user->id)
            ->where('class_session_id', $session->id)
            ->when($excludeBookingId, fn ($q) => $q->where('id', '!=', $excludeBookingId))
            ->where('status', BookingStatus::Confirmed)
            ->exists()) {
            throw new InvalidArgumentException('Вы уже забронировали место на это занятие.');
        }

        if (! $forAdmin) {
            $dayBookings = Booking::query()
                ->where('user_id', $user->id)
                ->where('status', BookingStatus::Confirmed)
                ->when($excludeBookingId, fn ($q) => $q->where('id', '!=', $excludeBookingId))
                ->whereHas('classSession', fn ($q) => $q->whereDate('starts_at', $session->starts_at->toDateString()))
                ->count();

            if ($dayBookings >= (int) config('studio.max_bookings_per_day')) {
                throw new InvalidArgumentException('В один день можно забронировать максимум '.config('studio.max_bookings_per_day').' занятия.');
            }
        }
    }

    public function weekStart(?string $weekParam = null): Carbon
    {
        if ($weekParam) {
            return Carbon::parse($weekParam)->startOfWeek(Carbon::MONDAY);
        }

        return now()->startOfWeek(Carbon::MONDAY);
    }

    public function scheduleOffset(?string $offsetParam = null): int
    {
        if ($offsetParam === null || $offsetParam === '') {
            return 0;
        }

        return max(0, (int) $offsetParam);
    }

    /** Начало видимого окна: сегодня + N недель (по 7 дней). */
    public function scheduleStart(int $weekOffset = 0): Carbon
    {
        return now()->startOfDay()->addDays($weekOffset * 7);
    }

    /**
     * Строки расписания: одно время — одна строка, в ячейках карточки по дням.
     *
     * @param  array<int, array{slots: list<array>}>  $days
     * @return list<array{start_minutes: int, time: string, cells: list<array|null>}>
     */
    public function buildScheduleRows(array $days): array
    {
        $startTimes = collect($days)
            ->flatMap(fn (array $day) => $day['slots'])
            ->pluck('start_minutes')
            ->unique()
            ->sort()
            ->values();

        if ($startTimes->isEmpty()) {
            return [];
        }

        return $startTimes->map(function (int $startMinutes) use ($days) {
            $cells = [];

            foreach ($days as $day) {
                $slot = collect($day['slots'])->firstWhere('start_minutes', $startMinutes);
                $cells[] = $slot ?: null;
            }

            return [
                'start_minutes' => $startMinutes,
                'time' => sprintf('%02d:%02d', intdiv($startMinutes, 60), $startMinutes % 60),
                'cells' => $cells,
            ];
        })->all();
    }

    /**
     * @param  array<int, array{key: string, name: string, date: string, label: string, is_today: bool, slots: list<array>}>
     */
    public function buildRollingSchedule(Carbon $startDate, ?User $viewer = null, ?Booking $rescheduleFrom = null): array
    {
        $rangeStart = $startDate->copy()->startOfDay();
        $rangeEnd = $startDate->copy()->addDays(6)->endOfDay();

        $sessionsQuery = ClassSession::query()
            ->inDateRange($rangeStart, $rangeEnd)
            ->where('status', ClassSessionStatus::Scheduled)
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

        $today = now()->startOfDay();
        $dayNames = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
        $dayKeys = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
        $days = [];

        for ($i = 0; $i < 7; $i++) {
            $date = $startDate->copy()->addDays($i);
            $dateKey = $date->toDateString();
            $daySessions = $sessions->get($dateKey, collect());
            $isToday = $date->equalTo($today);

            $slots = $daySessions
                ->map(fn (ClassSession $session) => $this->mapSessionToSlot($session, $viewer, $rescheduleFrom))
                ->values()
                ->all();

            $days[] = [
                'key' => $dayKeys[$date->dayOfWeekIso - 1],
                'name' => $dayNames[$date->dayOfWeekIso - 1],
                'date' => RussianDate::dayMonth($date),
                'label' => $isToday
                    ? 'Сегодня'
                    : mb_strtoupper(RussianDate::dayMonth($date)).' '.mb_strtoupper($dayNames[$date->dayOfWeekIso - 1]),
                'is_today' => $isToday,
                'slots' => $slots,
            ];
        }

        return $days;
    }

    /**
     * @return array<int, array{key: string, name: string, date: string, slots: list<array>}>
     */
    public function buildWeekSchedule(Carbon $weekStart, ?User $viewer = null, ?Booking $rescheduleFrom = null): array
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

            $slots = $daySessions->map(function (ClassSession $session) use ($viewer, $rescheduleFrom) {
                return $this->mapSessionToSlot($session, $viewer, $rescheduleFrom);
            })->values()->all();

            $week[] = [
                'key' => $dayKeys[$i],
                'name' => $dayNames[$i],
                'date' => RussianDate::dayMonth($date),
                'slots' => $slots,
            ];
        }

        return $week;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapSessionToSlot(ClassSession $session, ?User $viewer, ?Booking $rescheduleFrom): array
    {
        $userBooked = $viewer
            ? $session->bookings->isNotEmpty()
            : false;

        $isRescheduleSource = $rescheduleFrom !== null
            && $session->id === $rescheduleFrom->class_session_id;

        $canRescheduleHere = $rescheduleFrom !== null
            && ! $isRescheduleSource
            && $session->slotStatus() === 'open'
            && $session->isBookable()
            && ! $userBooked;

        $durationMinutes = $session->durationMinutes();
        $startMinutes = ((int) $session->starts_at->format('H')) * 60 + (int) $session->starts_at->format('i');
        $free = max(0, $session->capacity - (int) $session->taken);

        return [
            'id' => $session->id,
            'time' => $session->formattedTime(),
            'time_range' => $session->formattedTimeRange(),
            'duration_minutes' => $durationMinutes,
            'start_minutes' => $startMinutes,
            'title' => $session->title,
            'direction' => $session->direction?->title,
            // Слаг нужен мобильному приложению: по нему выбирается цвет семейства
            // и иконка направления. По названию сопоставлять ненадёжно —
            // тренер может завести занятие с произвольной темой.
            'direction_slug' => $session->direction?->slug,
            'topic' => $session->topic,
            'description' => $session->description,
            'trainer' => $session->trainerName(),
            'type' => $session->type->badgeClass(),
            'type_label' => $session->type->shortLabel(),
            'date_time' => $session->formattedDateTime(),
            'taken' => (int) $session->taken,
            'total' => $session->capacity,
            'free' => $free,
            'status' => $session->slotStatus(),
            'reason' => $session->cancellation_reason,
            'bookable' => $session->isBookable() && ! $userBooked && $rescheduleFrom === null,
            'user_booked' => $userBooked && ! $isRescheduleSource,
            'is_reschedule_source' => $isRescheduleSource,
            'can_reschedule_here' => $canRescheduleHere,
        ];
    }
}
