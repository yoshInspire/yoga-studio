<?php

namespace App\Services;

use App\Support\RussianDate;
use App\Enums\AttendanceStatus;
use App\Enums\BookingStatus;
use App\Enums\ClassSessionStatus;
use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Direction;
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
        private NotificationService $notifications,
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
     *
     * Возвращает абонемент, которому вернули занятие, — его остаток вырос, и
     * прежний выбор списания для будущих записей мог стать неверным
     * (см. `rebalanceFreedSessions()`). null — возвращать было нечего.
     */
    private function releaseBooking(Booking $booking): ?Subscription
    {
        $usage = $booking->subscriptionUsage;

        if ($usage === null) {
            return null;
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

                return null;
            }
        }

        $this->subscriptions->refundUsage($usage);

        return $subscription;
    }

    /**
     * Перевести будущие записи на абонемент, которому вернули занятие.
     *
     * Абонемент списания выбирается один раз — при создании брони — и раньше
     * никогда не пересматривался. Из-за этого занятия сгорали: клиент занимал
     * последнее занятие старого абонемента, следующую бронь система уводила на
     * новый (старый исчерпан), затем первую бронь отменяли — занятие
     * возвращалось на старый абонемент и догорало до даты окончания, хотя
     * будущая запись как раз попадала в его срок.
     *
     * Возврат занятия — единственный момент, когда прежний выбор мог стать
     * неверным, поэтому здесь пересчитываем его для ещё не прошедших записей.
     * Правило то же, что в `findUsableForUser()`: списываем с абонемента,
     * приобретённого раньше, — иначе перенос спорил бы с автовыбором.
     *
     * @return int сколько записей переставили
     */
    public function rebalanceFreedSessions(Subscription $freed, ?int $excludeBookingId = null): int
    {
        $freed = $freed->fresh();

        if ($freed === null || $freed->isDoublePerDay() || ! $freed->isActive()) {
            return 0;
        }

        $moved = 0;

        foreach ($this->bookingsToMoveOnto($freed, $excludeBookingId) as $booking) {
            if ($freed->sessionsRemaining() < 1) {
                break;
            }

            $this->releaseBooking($booking);

            $booking->update([
                'subscription_id' => $freed->id,
                'subscription_usage_id' => $this->chargeForBooking($booking->user, $booking->classSession, $freed),
            ]);

            $freed->refresh();
            $moved++;
        }

        return $moved;
    }

    /**
     * Будущие записи клиента, которые по правилу автовыбора должны были уйти
     * на этот абонемент, а списаны с более позднего.
     *
     * «Двойные» абонементы не трогаем ни с одной стороны: там оплачен день, а
     * не занятие, и перенос списания сломал бы дневной учёт.
     *
     * @return list<Booking>
     */
    private function bookingsToMoveOnto(Subscription $freed, ?int $excludeBookingId): array
    {
        return Booking::query()
            ->where('user_id', $freed->user_id)
            ->where('status', BookingStatus::Confirmed)
            ->where('subscription_id', '!=', $freed->id)
            ->where(fn ($q) => $q->where('attendance_status', AttendanceStatus::Expected)
                ->orWhereNull('attendance_status'))
            ->when($excludeBookingId, fn ($q) => $q->where('id', '!=', $excludeBookingId))
            // Занятие уже прошло — списание переносить некуда, клиент на нём был.
            ->whereHas('classSession', fn ($q) => $q
                ->where('starts_at', '>=', now())
                ->whereDate('starts_at', '>=', $freed->starts_at->toDateString())
                ->whereDate('starts_at', '<=', $freed->ends_at->toDateString()))
            ->with(['classSession', 'subscription', 'subscriptionUsage', 'user'])
            ->get()
            ->filter(fn (Booking $booking) => $booking->classSession !== null
                && $booking->user !== null
                && $booking->subscription !== null
                && ! $booking->subscription->isDoublePerDay()
                && $this->subscriptions->typesMatch($freed->type, $booking->classSession->type)
                && $this->isPreferredOver($freed, $booking->subscription))
            // Ближайшее занятие — первым: у него меньше шансов дожить до
            // следующего возврата, если занятий вернулось меньше, чем записей.
            ->sortBy(fn (Booking $booking) => $booking->classSession->starts_at->getTimestamp())
            ->values()
            ->all();
    }

    /**
     * Тот же порядок предпочтения, что и в `SubscriptionService::findUsableForUser()`.
     */
    private function isPreferredOver(Subscription $candidate, Subscription $current): bool
    {
        return $this->orderKey($candidate) < $this->orderKey($current);
    }

    /**
     * @return array{string, string, string, int}
     */
    private function orderKey(Subscription $subscription): array
    {
        return [
            $subscription->purchased_at->toDateString(),
            $subscription->starts_at->toDateString(),
            $subscription->ends_at->toDateString(),
            $subscription->id,
        ];
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

    /**
     * Перенести запись клиента на другое занятие — действие администратора.
     *
     * Отличается от `rescheduleByClient()` тремя вещами, и все три намеренны:
     * запись чужая (клиент звонит и просит переставить), сроки отмены не
     * действуют (администратор договаривается лично), а абонемент списания
     * можно сменить — например, когда прежний уже кончился.
     *
     * Всё остальное общее и трогать нельзя: занятие возвращается на прежний
     * абонемент и списывается с нового в одной транзакции, иначе остатки
     * разъедутся.
     */
    public function rescheduleByAdmin(
        Booking $booking,
        ClassSession $newSession,
        ?Subscription $subscription = null,
    ): Booking {
        return DB::transaction(function () use ($booking, $newSession, $subscription) {
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);
            $newSession = ClassSession::query()->lockForUpdate()->findOrFail($newSession->id);

            if (! $booking->isConfirmed()) {
                throw new InvalidArgumentException('Запись уже отменена — переносить нечего.');
            }

            if ($booking->class_session_id === $newSession->id) {
                throw new InvalidArgumentException('Клиент уже записан на это занятие.');
            }

            $subscription ??= $booking->subscription;

            if ($subscription === null) {
                throw new InvalidArgumentException(
                    'У записи нет абонемента — выберите, с какого списать занятие.',
                );
            }

            if ($subscription->user_id !== $booking->user_id) {
                throw new InvalidArgumentException('Выбранный абонемент принадлежит другому клиенту.');
            }

            if (! $this->subscriptions->typesMatch($subscription->type, $newSession->type)) {
                throw new InvalidArgumentException('Тип абонемента не подходит для этого занятия.');
            }

            $this->assertCanBook($booking->user, $newSession, excludeBookingId: $booking->id, forAdmin: true);

            // Сначала вернуть занятие на прежний абонемент, потом списать с
            // нового: иначе перенос внутри одного абонемента с последним
            // занятием упрётся в собственную же бронь.
            $freed = $this->releaseBooking($booking);

            $usageId = $this->chargeForBooking($booking->user, $newSession, $subscription);

            $booking->update([
                'class_session_id' => $newSession->id,
                'subscription_id' => $subscription->id,
                'subscription_usage_id' => $usageId,
            ]);

            // Администратор сменил абонемент списания — на прежнем освободилось
            // занятие. Саму перенесённую запись исключаем: её абонемент выбрал
            // администратор вручную, возвращать её обратно нельзя.
            if ($freed !== null && $freed->id !== $subscription->id) {
                $this->rebalanceFreedSessions($freed, $booking->id);
            }

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

            $freed = $this->releaseBooking($booking);

            $booking->update([
                'status' => BookingStatus::CancelledByClient,
                'cancelled_at' => now(),
                'subscription_usage_id' => null,
            ]);

            if ($freed !== null) {
                $this->rebalanceFreedSessions($freed, $booking->id);
            }

            return $booking->refresh();
        });
    }

    public function cancelClass(ClassSession $session, string $reason, bool $refundClients = true): ClassSession
    {
        $cancelled = DB::transaction(function () use ($session, $reason, $refundClients) {
            $session = ClassSession::query()->lockForUpdate()->findOrFail($session->id);

            if ($session->isCancelled()) {
                throw new InvalidArgumentException('Занятие уже отменено.');
            }

            $session->update([
                'status' => ClassSessionStatus::Cancelled,
                'cancellation_reason' => $reason,
                'cancelled_at' => now(),
            ]);

            /** @var array<int, Subscription> $freed */
            $freed = [];

            $session->bookings()
                ->where('status', BookingStatus::Confirmed)
                ->each(function (Booking $booking) use ($reason, $refundClients, &$freed) {
                    $released = $refundClients ? $this->releaseBooking($booking) : null;

                    if ($released !== null) {
                        $freed[$released->id] = $released;
                    }

                    $booking->update([
                        'status' => BookingStatus::ClassCancelled,
                        'cancellation_reason' => $reason,
                        'cancelled_at' => now(),
                        'subscription_usage_id' => null,
                    ]);
                });

            // Только после того, как все брони отменённого занятия сняты с
            // абонементов: иначе перенос увидел бы их как действующие записи.
            foreach ($freed as $subscription) {
                $this->rebalanceFreedSessions($subscription);
            }

            return $session->refresh();
        });

        // Тренер узнаёт об отмене здесь, а не в трёх местах вызова (админка,
        // API, автоотмена недобранных групп): их легко забыть, а приехать на
        // отменённое занятие — нет. Только лента и пуш, писем тренерам не шлём.
        $this->notifyTrainerOfCancellation($cancelled, $reason);

        return $cancelled;
    }

    private function notifyTrainerOfCancellation(ClassSession $session, string $reason): void
    {
        $trainer = $session->trainer;

        if ($trainer === null) {
            return;
        }

        $this->notifications->notifyStaff(
            $trainer,
            'Занятие отменено',
            [
                $session->title.' — '.$session->formattedDateTime().'.',
                $reason,
            ],
            type: 'cancel',
            payload: ['session_id' => $session->id],
        );
    }

    public function cancelByAdmin(Booking $booking, ?string $reason = null, bool $refund = true): Booking
    {
        return DB::transaction(function () use ($booking, $reason, $refund) {
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);

            if (! $booking->isConfirmed()) {
                throw new InvalidArgumentException('Запись уже отменена.');
            }

            $freed = $refund ? $this->releaseBooking($booking) : null;

            $booking->update([
                'status' => BookingStatus::CancelledByAdmin,
                'cancellation_reason' => $reason,
                'cancelled_at' => now(),
                'subscription_usage_id' => null,
            ]);

            if ($freed !== null) {
                $this->rebalanceFreedSessions($freed, $booking->id);
            }

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

            $freed = $booking->isCharged() ? $this->releaseBooking($booking) : null;

            $booking->update([
                'subscription_usage_id' => null,
                'attendance_status' => AttendanceStatus::NoShow,
                'attended_at' => now(),
            ]);

            if ($freed !== null) {
                $this->rebalanceFreedSessions($freed, $booking->id);
            }

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
     * Направления, по которым можно отфильтровать показанный период.
     *
     * В списке только те, у кого в этих семи днях есть занятия: чип, который
     * ничего не находит, обманывает — человек жмёт «Инь-йога» и получает
     * пустую сетку. Поэтому набор пересчитывается на каждую неделю.
     *
     * Исключение — уже выбранные направления ($keepSlugs): их чипы обязаны
     * остаться на месте, иначе, перелистнув на неделю без таких занятий,
     * фильтр нечем будет снять.
     *
     * @param  list<string>  $keepSlugs
     * @return list<array{slug: string, title: string}>
     */
    public function scheduleDirections(Carbon $rangeStart, Carbon $rangeEnd, array $keepSlugs = []): array
    {
        return Direction::query()
            ->where(fn ($query) => $query
                ->whereHas('classSessions', fn ($q) => $q
                    ->inDateRange($rangeStart, $rangeEnd)
                    ->where('status', ClassSessionStatus::Scheduled))
                ->when($keepSlugs !== [], fn ($q) => $q->orWhereIn('slug', $keepSlugs)))
            ->ordered()
            ->get(['id', 'slug', 'title'])
            ->map(fn (Direction $direction) => [
                'slug' => $direction->slug,
                'title' => $direction->title,
            ])
            ->all();
    }

    /**
     * Слаги направлений из адреса — в идентификаторы. Чужие слаги отбрасываются.
     *
     * @param  list<string>  $slugs
     * @return list<int>
     */
    public function scheduleDirectionIds(array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }

        return Direction::query()
            ->whereIn('slug', $slugs)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param  list<int>  $directionIds  пустой список — без фильтра
     * @param  array<int, array{key: string, name: string, date: string, label: string, is_today: bool, slots: list<array>}>
     */
    public function buildRollingSchedule(Carbon $startDate, ?User $viewer = null, ?Booking $rescheduleFrom = null, array $directionIds = []): array
    {
        $rangeStart = $startDate->copy()->startOfDay();
        $rangeEnd = $startDate->copy()->addDays(6)->endOfDay();

        $sessionsQuery = ClassSession::query()
            ->inDateRange($rangeStart, $rangeEnd)
            ->where('status', ClassSessionStatus::Scheduled)
            ->when($directionIds !== [], fn ($q) => $q->whereIn('direction_id', $directionIds))
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
                // Для узкой ленты дней на телефоне: «12 августа» в кнопку не
                // помещается, и на экране остаётся два дня из семи.
                'date_short' => $date->format('d.m'),
                'day_number' => $date->format('j'),
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
