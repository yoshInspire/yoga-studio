<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Enums\BookingStatus;
use App\Enums\ClassSessionStatus;
use App\Models\Booking;
use App\Models\ClassSession;
use App\Support\RussianDate;
use Illuminate\Support\Carbon;

class VisitControlService
{
    /**
     * @return array{
     *     date: Carbon,
     *     date_label: string,
     *     weekday: string,
     *     is_today: bool,
     *     stats: array{sessions: int, bookings: int, attended: int, no_show: int, pending: int},
     *     sessions: list<array{
     *         id: int,
     *         time: string,
     *         time_range: string,
     *         title: string,
     *         type_label: string,
     *         type: string,
     *         trainer: string|null,
     *         taken: int,
     *         capacity: int,
     *         status: string,
     *         is_cancelled: bool,
     *         attendees: list<array{
     *             booking_id: int,
     *             name: string,
     *             phone: string|null,
     *             phone_href: string|null,
     *             subscription_label: string,
     *             subscription_starts_at: string|null,
     *             sessions_remaining: int|null,
     *             sessions_total: int|null,
     *             charge_label: string,
     *             charge_color: string,
     *             attendance: string,
     *             attendance_label: string,
     *             attendance_color: string,
     *             attendance_pending: bool,
     *             health_note: string|null,
     *         }>,
     *     }>,
     * }
     */
    public function buildDay(Carbon $date): array
    {
        $date = $date->copy()->startOfDay();

        $sessions = ClassSession::query()
            ->whereDate('starts_at', $date)
            ->with([
                'direction',
                'trainer:id,first_name,last_name',
                'bookings' => fn ($q) => $q
                    ->where('status', BookingStatus::Confirmed)
                    ->with(['user', 'subscription', 'subscriptionUsage']),
            ])
            ->withCount(['bookings as taken' => fn ($q) => $q->where('status', BookingStatus::Confirmed)])
            ->orderBy('starts_at')
            ->get();

        $stats = [
            'sessions' => 0,
            'bookings' => 0,
            'attended' => 0,
            'no_show' => 0,
            'pending' => 0,
        ];

        $slots = [];

        foreach ($sessions as $session) {
            $stats['sessions']++;

            $attendees = [];
            foreach ($session->bookings as $booking) {
                $stats['bookings']++;
                $attendance = $booking->attendance_status ?? AttendanceStatus::Expected;

                match ($attendance) {
                    AttendanceStatus::Attended => $stats['attended']++,
                    AttendanceStatus::NoShow => $stats['no_show']++,
                    default => $stats['pending']++,
                };

                $attendees[] = $this->formatAttendee($booking);
            }

            $slots[] = [
                'id' => $session->id,
                'time' => $session->formattedTime(),
                'time_range' => $session->formattedTimeRange(),
                'title' => $session->title,
                'type_label' => $session->type->shortLabel(),
                'type' => $session->type->value,
                'trainer' => $session->type->value === 'individual' ? $session->trainerName() : null,
                'taken' => (int) $session->taken,
                'capacity' => $session->capacity,
                'status' => $session->slotStatus(),
                'is_cancelled' => $session->status === ClassSessionStatus::Cancelled,
                'attendees' => $attendees,
            ];
        }

        return [
            'date' => $date,
            'date_label' => RussianDate::dayMonthYear($date),
            'weekday' => mb_ucfirst($date->translatedFormat('l')),
            'is_today' => $date->isToday(),
            'stats' => $stats,
            'sessions' => $slots,
        ];
    }

    /**
     * @return array{
     *     booking_id: int,
     *     name: string,
     *     phone: string|null,
     *     phone_href: string|null,
     *     subscription_label: string,
     *     subscription_starts_at: string|null,
     *     sessions_remaining: int|null,
     *     sessions_total: int|null,
     *     charge_label: string,
     *     charge_color: string,
     *     attendance: string,
     *     attendance_label: string,
     *     attendance_color: string,
     *     attendance_pending: bool,
     *     health_note: string|null,
     * }
     */
    private function formatAttendee(Booking $booking): array
    {
        $user = $booking->user;
        $subscription = $booking->subscription;
        $attendance = $booking->attendance_status ?? AttendanceStatus::Expected;
        $charge = $booking->chargeStatus();
        $phone = $user?->phone;

        return [
            'booking_id' => $booking->id,
            'name' => $user?->fullName() ?? '—',
            'phone' => $phone,
            'phone_href' => filled($phone) ? 'tel:'.preg_replace('/\s+/', '', $phone) : null,
            'subscription_label' => $subscription?->type?->shortLabel() ?? '—',
            'subscription_starts_at' => $subscription?->starts_at?->format('d.m.Y'),
            'sessions_remaining' => $subscription?->sessionsRemaining(),
            'sessions_total' => $subscription?->sessions_total,
            'charge_label' => $charge['label'],
            'charge_color' => $charge['color'],
            'attendance' => $attendance->value,
            'attendance_label' => $attendance->label(),
            'attendance_color' => $attendance->badgeColor(),
            'attendance_pending' => $booking->attendancePending(),
            'health_note' => filled($user?->health_note) ? $user->health_note : null,
        ];
    }
}
