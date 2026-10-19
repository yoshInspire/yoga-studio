<?php

namespace App\Http\Controllers;

use App\Exceptions\SharedSubscriptionRequired;
use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\FamilyMember;
use App\Models\Subscription;
use App\Models\User;
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
            'for_user_id' => ['nullable', 'integer'],
        ]);

        $me = $request->user();
        $session = ClassSession::query()->findOrFail($request->integer('class_session_id'));
        $subscription = $request->filled('subscription_id')
            ? Subscription::query()->findOrFail($request->integer('subscription_id'))
            : null;

        // Кого записывают: себя или участника своей семьи.
        $attendee = ! $request->filled('for_user_id') || $request->integer('for_user_id') === $me->id
            ? $me
            : User::query()->find($request->integer('for_user_id'));

        if ($attendee === null || ($attendee->id !== $me->id && ! FamilyMember::inSameFamily($me, $attendee))) {
            return back()->withErrors(['booking' => 'Записать можно только участника своей семьи.']);
        }

        try {
            $booking = $bookings->bookFor($me, $attendee, $session, $subscription);
            $booking->load(['classSession', 'subscription.user']);
            $this->adminActivity->clientBooked($attendee, $booking, $me);
        } catch (SharedSubscriptionRequired $e) {
            // Своего абонемента нет, а семья открыла свои: страница расписания
            // покажет кнопки «Записаться по абонементу…» — согласие человека.
            return back()->with('family_booking_offer', [
                'session_id' => $session->id,
                'title' => $session->title,
                'date_time' => $session->formattedDateTime(),
                'for_user_id' => $attendee->id === $me->id ? null : $attendee->id,
                'for_name' => $attendee->id === $me->id ? null : $attendee->shortName(),
                'sources' => $bookings->presentSources($e->subscriptions, $attendee, $me),
            ]);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['booking' => $e->getMessage()]);
        }

        $owner = $booking->subscription !== null && $booking->subscription->user_id !== $attendee->id
            ? $booking->subscription->user
            : null;

        return back()->with('status', ($attendee->id === $me->id
                ? 'Место на занятие «'.$session->title.'» забронировано.'
                : $attendee->shortName().' записан(а) на занятие «'.$session->title.'».')
            .($owner !== null ? ' Занятие спишется с абонемента семьи ('.$owner->shortName().').' : ''));
    }

    public function reschedule(Request $request, Booking $booking, BookingService $bookings): RedirectResponse
    {
        if (! $booking->isManageableBy($request->user())) {
            abort(403);
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
            return back()->withErrors(['booking' => $e->getMessage()]);
        }

        return redirect()
            ->route('account')
            ->with('lk_section', 'bookings')
            ->with('status', 'Бронирование перенесено на «'.$session->title.'» '.$session->formattedDateTime().'.');
    }

    public function cancel(Request $request, Booking $booking, BookingService $bookings): RedirectResponse
    {
        if (! $booking->isManageableBy($request->user())) {
            abort(403);
        }

        try {
            $booking->load(['classSession', 'user']);
            $bookings->cancelByClient($booking);
            $this->adminActivity->clientCancelledBooking($booking->user, $booking, $request->user());
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
