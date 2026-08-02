<?php

namespace App\Http\Controllers;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Services\BookingService;
use App\Support\RussianDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ScheduleController extends Controller
{
    public function __invoke(Request $request, BookingService $bookings): View|RedirectResponse|JsonResponse
    {
        $data = $this->buildScheduleData($request, $bookings);

        if ($data instanceof RedirectResponse) {
            return $data;
        }

        if ($request->ajax() || $request->wantsJson() || $request->boolean('ajax')) {
            return response()->json([
                'offset' => $data['offset'],
                'canGoPrev' => $data['canGoPrev'],
                'prevOffset' => $data['prevOffset'],
                'nextOffset' => $data['nextOffset'],
                'rangeLabel' => $data['rangeLabel'],
                'html' => view('partials.schedule-grid', $data)->render(),
            ]);
        }

        return view('pages.schedule', $data);
    }

    /**
     * @return array<string, mixed>|RedirectResponse
     */
    private function buildScheduleData(Request $request, BookingService $bookings): array|RedirectResponse
    {
        $offset = $bookings->scheduleOffset($request->query('offset'));
        $startDate = $bookings->scheduleStart($offset);
        $endDate = $startDate->copy()->addDays(6);
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
                            ?? 'Не удалось перенести это бронирование.',
                    ]);
            }
        }

        $days = $bookings->buildRollingSchedule($startDate, $viewer, $rescheduleFrom);
        $rows = $bookings->buildScheduleRows($days);

        return [
            'days' => $days,
            'rows' => $rows,
            'offset' => $offset,
            'canGoPrev' => $offset > 0,
            'prevOffset' => max(0, $offset - 1),
            'nextOffset' => $offset + 1,
            'rangeLabel' => RussianDate::dayMonthRange($startDate, $endDate),
            'rescheduleFrom' => $rescheduleFrom,
            'typeLabels' => [
                'group' => 'Групповое',
                'indiv' => 'Индивидуальное',
                'event' => 'Мероприятие',
            ],
        ];
    }
}
