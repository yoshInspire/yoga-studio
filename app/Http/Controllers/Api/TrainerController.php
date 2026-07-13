<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BookingService;
use App\Services\TrainerService;
use App\Support\RussianDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrainerController extends Controller
{
    /** Расписание тренера на неделю со списком записавшихся. */
    public function index(Request $request, TrainerService $trainerService, BookingService $bookings): JsonResponse
    {
        $trainer = $request->user();
        $weekStart = $bookings->weekStart($request->query('week'));
        $weekEnd = $weekStart->copy()->addDays(6);

        return response()->json([
            'week' => $trainerService->buildWeekSchedule($trainer, $weekStart),
            'week_label' => RussianDate::dayMonthRange($weekStart, $weekEnd),
            'prev_week' => $weekStart->copy()->subWeek()->toDateString(),
            'next_week' => $weekStart->copy()->addWeek()->toDateString(),
            'current_week' => $weekStart->copy()->subWeek()->lt(now()->startOfWeek()),
        ]);
    }
}
