<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\ClassSession;
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
        ]);

        $session = ClassSession::query()->findOrFail($request->integer('class_session_id'));

        try {
            $booking = $bookings->book($request->user(), $session);
            $booking->load('classSession');
            $this->adminActivity->clientBooked($request->user(), $booking);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['booking' => $e->getMessage()]);
        }

        return back()->with('status', 'Место на занятие «'.$session->title.'» забронировано.');
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

    public function cancel(Booking $booking, BookingService $bookings): RedirectResponse
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
