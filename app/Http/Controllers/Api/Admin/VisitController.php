<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\BookingService;
use App\Services\VisitControlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Контроль посещений за день — то же, что страница «Посещения» в админке.
 *
 * Считает день один сервис (`VisitControlService`), поэтому цифры остатков в
 * приложении и в вебе не могут разойтись. Отметки идут через `BookingService`:
 * неявка возвращает занятие на абонемент, и делать это руками нельзя.
 */
class VisitController extends Controller
{
    /** Занятия дня с ростером и отметками. */
    public function day(Request $request, VisitControlService $visits): JsonResponse
    {
        $data = $request->validate(['date' => ['nullable', 'date']]);

        $date = filled($data['date'] ?? null)
            ? Carbon::parse($data['date'])->startOfDay()
            : now()->startOfDay();

        $day = $visits->buildDay($date);

        return response()->json([
            'date' => $date->toDateString(),
            'date_label' => $day['date_label'],
            'weekday' => $day['weekday'],
            'is_today' => $day['is_today'],
            'today' => now()->toDateString(),
            'prev_date' => $date->copy()->subDay()->toDateString(),
            'next_date' => $date->copy()->addDay()->toDateString(),
            'stats' => $day['stats'],
            'sessions' => $day['sessions'],
        ]);
    }

    /** Клиент пришёл. */
    public function attended(Booking $booking, BookingService $bookings): JsonResponse
    {
        return $this->run(
            fn () => $bookings->markAttended($booking),
            'Присутствие отмечено.',
        );
    }

    /** Клиент не пришёл: занятие возвращается на абонемент. */
    public function noShow(Booking $booking, BookingService $bookings): JsonResponse
    {
        return $this->run(
            fn () => $bookings->markNoShow($booking),
            'Неявка отмечена, занятие возвращено на абонемент.',
        );
    }

    /** Снять запись. */
    public function cancel(Request $request, Booking $booking, BookingService $bookings): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        return $this->run(
            fn () => $bookings->cancelByAdmin(
                $booking,
                $data['reason'] ?? 'Отменено в контроле посещений',
            ),
            'Запись отменена, занятие возвращено на абонемент.',
        );
    }

    /** Сервисы отказываются работать с уже отменённой записью — это 422, не 500. */
    private function run(callable $action, string $message): JsonResponse
    {
        try {
            $action();
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => $message]);
    }
}
