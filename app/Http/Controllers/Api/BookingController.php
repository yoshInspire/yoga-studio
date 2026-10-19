<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\SharedSubscriptionRequired;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\FamilyMember;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AdminActivityNotifier;
use App\Services\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class BookingController extends Controller
{
    public function __construct(
        protected AdminActivityNotifier $adminActivity,
    ) {}

    /**
     * С каких абонементов можно оплатить занятие — свои и открытые семьёй.
     * Приложение спрашивает это до записи: если вариантов несколько или
     * подходит только чужой, человек выбирает сам.
     */
    public function sources(Request $request, BookingService $bookings): JsonResponse
    {
        $request->validate([
            'class_session_id' => ['required', 'integer', 'exists:class_sessions,id'],
            'for_user_id' => ['nullable', 'integer'],
        ]);

        $session = ClassSession::query()->findOrFail($request->integer('class_session_id'));
        $attendee = $this->attendee($request);

        if ($attendee === null) {
            return response()->json(['message' => 'Записать можно только участника своей семьи.'], 422);
        }

        return response()->json(['data' => $bookings->bookingSources($attendee, $session, $request->user())]);
    }

    /**
     * Кого записывают: себя или участника своей семьи (`for_user_id`). null —
     * чужой человек: его абонементы показывать нельзя.
     */
    private function attendee(Request $request): ?User
    {
        $me = $request->user();

        if (! $request->filled('for_user_id') || $request->integer('for_user_id') === $me->id) {
            return $me;
        }

        $attendee = User::query()->find($request->integer('for_user_id'));

        return $attendee !== null && FamilyMember::inSameFamily($me, $attendee) ? $attendee : null;
    }

    /**
     * Записаться на занятие.
     *
     * `subscription_id` необязателен: без него списание идёт со своего
     * абонемента. Своего нет, а семья открыла свои — 422 с
     * `code: shared_subscription_required` и вариантами в `sources`.
     */
    public function store(Request $request, BookingService $bookings): JsonResponse
    {
        $request->validate([
            'class_session_id' => ['required', 'integer', 'exists:class_sessions,id'],
            'subscription_id' => ['nullable', 'integer', 'exists:subscriptions,id'],
            'for_user_id' => ['nullable', 'integer'],
        ]);

        $me = $request->user();
        $session = ClassSession::query()->findOrFail($request->integer('class_session_id'));
        $subscription = $request->filled('subscription_id')
            ? Subscription::query()->findOrFail($request->integer('subscription_id'))
            : null;
        $attendee = $this->attendee($request);

        if ($attendee === null) {
            return response()->json(['message' => 'Записать можно только участника своей семьи.'], 422);
        }

        try {
            $booking = $bookings->bookFor($me, $attendee, $session, $subscription);
            $booking->load(['classSession', 'subscription.user']);
            $this->adminActivity->clientBooked($attendee, $booking, $me);
        } catch (SharedSubscriptionRequired $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'shared_subscription_required',
                'sources' => $bookings->presentSources($e->subscriptions, $attendee, $me),
            ], 422);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $owner = $booking->subscription !== null && $booking->subscription->user_id !== $attendee->id
            ? $booking->subscription->user
            : null;

        return response()->json([
            'message' => ($attendee->id === $me->id
                    ? 'Место на занятие «'.$session->title.'» забронировано.'
                    : $attendee->shortName().' записан(а) на занятие «'.$session->title.'».')
                .($owner !== null ? ' Занятие спишется с абонемента семьи ('.$owner->shortName().').' : ''),
        ]);
    }

    /** Отменить свою запись или запись, сделанную мной за члена семьи. */
    public function cancel(Request $request, Booking $booking, BookingService $bookings): JsonResponse
    {
        if (! $booking->isManageableBy($request->user())) {
            return response()->json(['message' => 'Нет доступа.'], 403);
        }

        try {
            $booking->load(['classSession', 'user']);
            $bookings->cancelByClient($booking);
            $this->adminActivity->clientCancelledBooking($booking->user, $booking, $request->user());
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Бронирование отменено. Занятие возвращено на абонемент.']);
    }

    /** Перенести запись на другое занятие. */
    public function reschedule(Request $request, Booking $booking, BookingService $bookings): JsonResponse
    {
        if (! $booking->isManageableBy($request->user())) {
            return response()->json(['message' => 'Нет доступа.'], 403);
        }

        $request->validate([
            'class_session_id' => ['required', 'integer', 'exists:class_sessions,id'],
        ]);

        $session = ClassSession::query()->findOrFail($request->integer('class_session_id'));
        $fromSession = $booking->classSession;

        try {
            $bookings->rescheduleByClient($booking, $session);
            $this->adminActivity->clientRescheduledBooking($booking->user, $fromSession, $session, $request->user());
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Бронирование перенесено на «'.$session->title.'».']);
    }
}
