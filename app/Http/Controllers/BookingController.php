<?php

namespace App\Http\Controllers;

use App\Exceptions\SharedSubscriptionRequired;
use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Subscription;
use App\Services\AdminActivityNotifier;
use App\Services\BookingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class BookingController extends Controller
{
    public function __construct(
        protected AdminActivityNotifier $adminActivity,
    ) {}

    public function store(Request $request, BookingService $bookings): RedirectResponse
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
            // Своего абонемента нет, а семья открыла свои: страница расписания
            // покажет кнопки «Записаться по абонементу…» — согласие человека.
            return back()->with('family_booking_offer', [
                'session_id' => $session->id,
                'title' => $session->title,
                'date_time' => $session->formattedDateTime(),
                'sources' => $bookings->presentSources($e->subscriptions, $request->user()),
            ]);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['booking' => $e->getMessage()]);
        }

        $owner = $booking->subscription !== null && $booking->subscription->user_id !== $request->user()->id
            ? $booking->subscription->user
            : null;

        return back()->with('status', 'Место на занятие «'.$session->title.'» забронировано.'
            .($owner !== null ? ' Занятие спишется с абонемента семьи ('.$owner->shortName().').' : ''));
    }

    public function reschedule(Request $request, Booking $booking, BookingService $bookings): RedirectResponse
    {
        if ($booking->user_id !== auth()->id()) {
            abort(403);
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
            return back()->withErrors(['booking' => $e->getMessage()]);
        }

        return redirect()
            ->route('account')
            ->with('lk_section', 'bookings')
            ->with('status', 'Бронирование перенесено на «'.$session->title.'» '.$session->formattedDateTime().'.');
    }

    public function cancel(Request $request, Booking $booking, BookingService $bookings): RedirectResponse
    {
        if ($booking->user_id !== auth()->id()) {
            abort(403);
        }

        try {
            $booking->load('classSession');
            $bookings->cancelByClient($booking);
            $this->adminActivity->clientCancelledBooking($request->user(), $booking);
        } catch (InvalidArgumentException $e) {
            return redirect()
                ->route('account')
                ->with('lk_section', 'bookings')
                ->withErrors(['booking' => $e->getMessage()]);
        }

        return redirect()
            ->route('account')
            ->with('lk_section', 'bookings')
            ->with('status', 'Бронирование отменено. Занятие возвращено на абонемент.');
    }
}
