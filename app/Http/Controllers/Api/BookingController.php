<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\SharedSubscriptionRequired;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Subscription;
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
        ]);

        $session = ClassSession::query()->findOrFail($request->integer('class_session_id'));

        return response()->json(['data' => $bookings->bookingSources($request->user(), $session)]);
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
        ]);

        $session = ClassSession::query()->findOrFail($request->integer('class_session_id'));
        $subscription = $request->filled('subscription_id')
            ? Subscription::query()->findOrFail($request->integer('subscription_id'))
            : null;

        try {
            $booking = $bookings->book($request->user(), $session, $subscription);
            $booking->load(['classSession', 'subscription.user']);
            $this->adminActivity->clientBooked($request->user(), $booking);
        } catch (SharedSubscriptionRequired $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'shared_subscription_required',
                'sources' => $bookings->presentSources($e->subscriptions, $request->user()),
            ], 422);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $owner = $booking->subscription !== null && $booking->subscription->user_id !== $request->user()->id
            ? $booking->subscription->user
            : null;

        return response()->json([
            'message' => 'Место на занятие «'.$session->title.'» забронировано.'
                .($owner !== null ? ' Занятие спишется с абонемента семьи ('.$owner->shortName().').' : ''),
        ]);
    }

    /** Отменить свою запись. */
    public function cancel(Request $request, Booking $booking, BookingService $bookings): JsonResponse
    {
        if ($booking->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Нет доступа.'], 403);
        }

        try {
            $booking->load('classSession');
            $bookings->cancelByClient($booking);
            $this->adminActivity->clientCancelledBooking($request->user(), $booking);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Бронирование отменено. Занятие возвращено на абонемент.']);
    }

    /** Перенести запись на другое занятие. */
    public function reschedule(Request $request, Booking $booking, BookingService $bookings): JsonResponse
    {
        if ($booking->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Нет доступа.'], 403);
        }

        $request->validate([
            'class_session_id' => ['required', 'integer', 'exists:class_sessions,id'],
        ]);

        $session = ClassSession::query()->findOrFail($request->integer('class_session_id'));
        $fromSession = $booking->classSession;

        try {
            $bookings->rescheduleByClient($booking, $session);
            $this->adminActivity->clientRescheduledBooking($request->user(), $fromSession, $session);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Бронирование перенесено на «'.$session->title.'».']);
    }
}
