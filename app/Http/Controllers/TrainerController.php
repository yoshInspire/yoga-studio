<?php

namespace App\Http\Controllers;

use App\Services\BookingService;
use App\Services\TrainerService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TrainerController extends Controller
{
    public function __invoke(Request $request, TrainerService $trainerService, BookingService $bookings): View
    {
        $trainer = $request->user();
        $weekStart = $bookings->weekStart($request->query('week'));
        $weekEnd = $weekStart->copy()->addDays(6);

        return view('pages.trainer', [
            'trainer' => $trainer,
            'week' => $trainerService->buildWeekSchedule($trainer, $weekStart),
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
