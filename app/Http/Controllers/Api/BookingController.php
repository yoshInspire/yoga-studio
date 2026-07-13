<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\ClassSession;
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

    /** Записаться на занятие. */
    public function store(Request $request, BookingService $bookings): JsonResponse
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
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Вы записаны на занятие «'.$session->title.'».']);
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

        return response()->json(['message' => 'Запись отменена. Занятие возвращено на абонемент.']);
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

        return response()->json(['message' => 'Запись перенесена на «'.$session->title.'».']);
    }
}
