<?php

namespace App\Services;

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
                'bookings' => fn ($q) => $q
                    ->where('status', BookingStatus::Confirmed)
                    ->with('user:id,first_name,last_name'),
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
                    ->map(fn ($booking) => [
                        'name' => trim($booking->user->first_name.' '.$booking->user->last_name),
                    ])
                    ->values()
                    ->all();

                return [
                    'time' => $session->formattedTime(),
                    'title' => $session->title,
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
                'date' => $date->translatedFormat('j F'),
                'slots' => $slots,
            ];
        }

        return $week;
    }
}
