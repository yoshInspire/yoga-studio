<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\ClassSession;
use App\Services\BookingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class BookingController extends Controller
{
    public function store(Request $request, BookingService $bookings): RedirectResponse
    {
        $request->validate([
            'class_session_id' => ['required', 'integer', 'exists:class_sessions,id'],
        ]);

        $session = ClassSession::query()->findOrFail($request->integer('class_session_id'));

        try {
            $bookings->book($request->user(), $session);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['booking' => $e->getMessage()]);
        }

        return back()->with('status', 'Вы записаны на занятие «'.$session->title.'».');
    }

    public function cancel(Booking $booking, BookingService $bookings): RedirectResponse
    {
        if ($booking->user_id !== auth()->id()) {
            abort(403);
        }

        try {
            $bookings->cancelByClient($booking);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['booking' => $e->getMessage()]);
        }

        return back()->with('status', 'Запись отменена. Занятие возвращено на абонемент.');
    }
}
