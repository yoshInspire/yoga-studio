<?php

namespace App\Services;

use App\Support\RussianDate;
use App\Enums\BookingStatus;
use App\Models\ClassSession;
use App\Models\User;
use Illuminate\Support\Carbon;

class TrainerService
{
    /**
     * @return array<int, array{key: string, name: string, date: string, slots: list<array>}>
     */
    public function buildWeekSchedule(User $trainer, Carbon $weekStart): array
    {
        $sessions = ClassSession::query()
            ->where('trainer_id', $trainer->id)
            ->inWeek($weekStart)
            ->with([
                'direction',
                'bookings' => fn ($q) => $q
                    ->where('status', BookingStatus::Confirmed)
                    ->with('user:id,first_name,last_name,health_note,health_note_visible_to_trainer'),
            ])
            ->withCount(['bookings as taken' => fn ($q) => $q->where('status', BookingStatus::Confirmed)])
            ->orderBy('starts_at')
            ->get()
            ->groupBy(fn (ClassSession $s) => $s->starts_at->toDateString());

        $dayNames = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
        $dayKeys = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
        $week = [];

        for ($i = 0; $i < 7; $i++) {
            $date = $weekStart->copy()->addDays($i);
            $dateKey = $date->toDateString();
            $daySessions = $sessions->get($dateKey, collect());

            $slots = $daySessions->map(function (ClassSession $session) {
                $attendees = $session->bookings
                    ->map(function ($booking) {
                        $user = $booking->user;
                        $entry = [
                            'name' => trim($user->first_name.' '.$user->last_name),
                        ];

                        if ($user->health_note_visible_to_trainer && filled($user->health_note)) {
                            $entry['health_note'] = $user->health_note;
                        }

                        return $entry;
                    })
                    ->values()
                    ->all();

                return [
                    'time' => $session->formattedTime(),
                    'title' => $session->title,
                    'direction' => $session->direction?->title,
                    'topic' => $session->topic,
                    'type' => $session->type->badgeClass(),
                    'type_label' => $session->type->shortLabel(),
                    'taken' => (int) $session->taken,
                    'total' => $session->capacity,
                    'status' => $session->slotStatus(),
                    'reason' => $session->cancellation_reason,
                    'attendees' => $attendees,
                ];
            })->values()->all();

            $week[] = [
                'key' => $dayKeys[$i],
                'name' => $dayNames[$i],
                'date' => RussianDate::dayMonth($date),
                'slots' => $slots,
            ];
        }

        return $week;
    }
}
