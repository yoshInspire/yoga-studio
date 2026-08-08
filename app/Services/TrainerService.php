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
     * Неделя тренера — общая для страницы сайта и мобильного приложения.
     * Ключи только добавляются: страница `pages.trainer` читает их по именам.
     *
     * @return array<int, array{key: string, name: string, date: string, date_iso: string, is_today: bool, slots: list<array>}>
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
                    ->with('user:id,first_name,last_name,avatar_path,health_note,health_note_visible_to_trainer'),
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
                            // Ключ строки в списке приложения. Контактов тренеру
                            // по-прежнему не отдаём — только имя и фамилия.
                            'booking_id' => $booking->id,
                            'name' => trim($user->first_name.' '.$user->last_name),
                            'initials' => $user->initials(),
                            'avatar' => $user->avatarUrl(),
                        ];

                        if ($user->health_note_visible_to_trainer && filled($user->health_note)) {
                            $entry['health_note'] = $user->health_note;
                        }

                        return $entry;
                    })
                    ->values()
                    ->all();

                return [
                    'id' => $session->id,
                    'time' => $session->formattedTime(),
                    'time_range' => $session->formattedTimeRange(),
                    'title' => $session->title,
                    'direction' => $session->direction?->title,
                    // Слаг, а не название: направления студия переименовывает
                    // через админку, а цвет и иконку приложение подбирает по слагу.
                    'direction_slug' => $session->direction?->slug,
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
                // Машинная дата рядом со словесной: приложению нужен день, а не
                // подпись, а разбирать «6 августа» обратно — лишняя работа.
                'date_iso' => $dateKey,
                'is_today' => $date->isToday(),
                'slots' => $slots,
            ];
        }

        return $week;
    }
}
