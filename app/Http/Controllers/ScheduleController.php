<?php

namespace App\Http\Controllers;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Services\BookingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ScheduleController extends Controller
{
    public function __invoke(Request $request, BookingService $bookings): View|RedirectResponse
    {
        $weekStart = $bookings->weekStart($request->query('week'));
        $weekEnd = $weekStart->copy()->addDays(6);
        $viewer = auth()->user();
        $rescheduleFrom = null;

        if ($request->filled('reschedule') && $viewer?->isClient()) {
            $rescheduleFrom = Booking::query()
                ->where('user_id', $viewer->id)
                ->where('status', BookingStatus::Confirmed)
                ->with('classSession')
                ->find($request->integer('reschedule'));

            if ($rescheduleFrom === null || ! $rescheduleFrom->canBeRescheduledByClient()) {
                return redirect()
                    ->route('account')
                    ->with('lk_section', 'bookings')
                    ->withErrors([
                        'booking' => $rescheduleFrom?->rescheduleBlockedMessage()
                            ?? 'Не удалось перенести эту запись.',
                    ]);
            }
        }

        return view('pages.schedule', [
            'week' => $bookings->buildWeekSchedule($weekStart, $viewer, $rescheduleFrom),
            'weekLabel' => $weekStart->translatedFormat('j F').' – '.$weekEnd->translatedFormat('j F'),
            'prevWeek' => $weekStart->copy()->subWeek()->toDateString(),
            'nextWeek' => $weekStart->copy()->addWeek()->toDateString(),
            'rescheduleFrom' => $rescheduleFrom,
            'typeLabels' => [
                'group' => 'Групповое',
                'indiv' => 'Индивидуальное',
                'event' => 'Мероприятие',
            ],
        ]);
    }
}
