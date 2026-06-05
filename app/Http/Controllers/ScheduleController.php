<?php

namespace App\Http\Controllers;

use App\Services\BookingService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ScheduleController extends Controller
{
    public function __invoke(Request $request, BookingService $bookings): View
    {
        $weekStart = $bookings->weekStart($request->query('week'));
        $weekEnd = $weekStart->copy()->addDays(6);
        $viewer = auth()->user();

        return view('pages.schedule', [
            'week' => $bookings->buildWeekSchedule($weekStart, $viewer),
            'weekLabel' => $weekStart->translatedFormat('j F').' – '.$weekEnd->translatedFormat('j F'),
            'prevWeek' => $weekStart->copy()->subWeek()->toDateString(),
            'nextWeek' => $weekStart->copy()->addWeek()->toDateString(),
            'typeLabels' => [
                'group' => 'Групповое',
                'indiv' => 'Индивидуальное',
                'event' => 'Мероприятие',
            ],
        ]);
    }
}
