<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\ClassSessionStatus;
use App\Enums\SubscriptionType;
use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Subscription;
use App\Models\User;
use App\Support\RussianDate;
use App\Support\VisitDatesFormatter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ReportService
{
    /**
     * @return Collection<int, Subscription>
     */
    public function subscriptionsByType(SubscriptionType $type): Collection
    {
        return Subscription::query()
            ->forType($type)
            ->with([
                'user:id,first_name,last_name,patronymic,phone',
                'usages' => fn ($query) => $query->orderBy('used_at'),
            ])
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Списания абонемента, по которым занятие уже физически прошло на дату отчёта.
     *
     * @return Collection<int, \App\Models\SubscriptionUsage>
     */
    public function completedUsagesForSubscription(Subscription $subscription, ?Carbon $asOf = null): Collection
    {
        $asOf ??= now();

        return $subscription->usages
            ->filter(fn ($usage) => $usage->used_at->lt($asOf))
            ->values();
    }

    /**
     * Сколько занятий списано по фактически прошедшим посещениям на дату отчёта.
     *
     * Учитывает и записи subscription_usages, и ручную правку «Списано» в карточке
     * абонемента (без отдельной usage-записи). Будущие списания (used_at >= asOf)
     * из sessions_used исключаются.
     */
    public function completedSessionsUsed(Subscription $subscription, ?Carbon $asOf = null): int
    {
        $asOf ??= now();

        $fromCompletedUsages = (int) $this->completedUsagesForSubscription($subscription, $asOf)
            ->sum(fn ($usage) => max(1, (int) $usage->sessions_spent));

        $futureUsagesSpent = (int) $subscription->usages
            ->filter(fn ($usage) => $usage->used_at->gte($asOf))
            ->sum(fn ($usage) => max(1, (int) $usage->sessions_spent));

        $fromSubscriptionBalance = max(0, $subscription->sessions_used - $futureUsagesSpent);

        // Баланс абонемента — источник правды: если администратор откатил лишнее
        // списание вручную, отчёт не должен показывать больше, чем в карточке.
        return min(
            $subscription->sessions_used,
            max($fromCompletedUsages, $fromSubscriptionBalance),
        );
    }

    /**
     * Остаток абонемента на дату отчёта (без будущих записей).
     */
    public function sessionsRemainingAsOf(Subscription $subscription, ?Carbon $asOf = null): int
    {
        return max(0, $subscription->sessions_total - $this->completedSessionsUsed($subscription, $asOf));
    }

    /**
     * Занятия, уже списанные под будущие записи клиента на дату отчёта.
     *
     * Это разница между балансом абонемента и фактически прошедшими занятиями:
     * именно на неё «Остаток» в отчёте раньше расходился с карточкой абонемента.
     */
    public function reservedSessionsAsOf(Subscription $subscription, ?Carbon $asOf = null): int
    {
        return max(0, $subscription->sessions_used - $this->completedSessionsUsed($subscription, $asOf));
    }

    /**
     * Даты посещений для строки отчёта «Абонементы» (только прошедшие занятия).
     */
    public function visitDatesForSubscription(Subscription $subscription, ?Carbon $asOf = null): string
    {
        $dates = $this->completedUsagesForSubscription($subscription, $asOf)
            ->map(fn ($usage) => $usage->used_at->format('d.m.Y'))
            ->values()
            ->all();

        if ($dates !== []) {
            return implode(', ', $dates);
        }

        $note = $subscription->admin_note ?? '';

        if (preg_match('/Посещения:\s*(.+?)(?:\n|$)/u', $note, $matches)) {
            return VisitDatesFormatter::normalizeList(trim($matches[1]), $subscription);
        }

        return '';
    }

    /**
     * @return Collection<int, ClassSession>
     */
    public function sessionsForWeeklyBookings(Carbon $weekStart): Collection
    {
        return ClassSession::query()
            ->inWeek($weekStart)
            ->where('status', ClassSessionStatus::Scheduled)
            ->with([
                'trainer:id,first_name,last_name',
                'bookings' => fn ($q) => $q
                    ->where('status', BookingStatus::Confirmed)
                    ->with('user:id,first_name,last_name,patronymic'),
            ])
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * Сетка недельного отчёта по записям: 7 столбцов (дни), в каждом — строки с занятиями и ФИО.
     *
     * @return array{
     *     headers: list<string>,
     *     columns: list<list<string>>,
     *     week_label: string,
     * }
     */
    public function buildWeeklyBookingsGrid(Carbon $weekStart): array
    {
        $weekStart = $weekStart->copy()->startOfWeek(Carbon::MONDAY);
        $sessions = $this->sessionsForWeeklyBookings($weekStart)
            ->groupBy(fn (ClassSession $session) => $session->starts_at->toDateString());

        $headers = [];
        $columns = [];

        for ($i = 0; $i < 7; $i++) {
            $date = $weekStart->copy()->addDays($i);
            $headers[] = RussianDate::weekdayHeader($date);
            $columns[] = $this->weeklyBookingsLinesForDay(
                $sessions->get($date->toDateString(), collect()),
            );
        }

        return [
            'headers' => $headers,
            'columns' => $columns,
            'week_label' => RussianDate::dayMonthRange($weekStart, $weekStart->copy()->addDays(6)),
        ];
    }

    /**
     * @param  Collection<int, ClassSession>  $daySessions
     * @return list<string>
     */
    private function weeklyBookingsLinesForDay(Collection $daySessions): array
    {
        if ($daySessions->isEmpty()) {
            return [];
        }

        $lines = [];

        foreach ($daySessions as $session) {
            if ($lines !== []) {
                $lines[] = '';
            }

            $lines[] = sprintf(
                '%s %s · %s',
                $session->formattedTime(),
                $session->type->shortLabel(),
                $session->trainerName(),
            );

            $attendees = $session->bookings
                ->sortBy(fn ($booking) => [
                    $booking->user->last_name ?? '',
                    $booking->user->first_name ?? '',
                    $booking->user->patronymic ?? '',
                ])
                ->map(fn ($booking) => '  '.$booking->user->fullName())
                ->values()
                ->all();

            if ($attendees === []) {
                $lines[] = '  нет записей';

                continue;
            }

            array_push($lines, ...$attendees);
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    public function visitMonths(?Carbon $from = null, ?Carbon $to = null): array
    {
        // Месяц собираем в PHP, а не через DATE_FORMAT: эта функция есть в
        // MySQL и нет в SQLite, из-за чего отчёт нельзя было проверить тестом.
        // Дат тут немного (по одной на прошедшее занятие с записью), а
        // datetime в базе лежат в часовом поясе приложения — строка выходит
        // та же самая.
        $dates = ClassSession::query()
            ->where('starts_at', '<', now())
            ->when($from, fn (Builder $q) => $q->where('starts_at', '>=', $from->copy()->startOfDay()))
            ->when($to, fn (Builder $q) => $q->where('starts_at', '<=', $to->copy()->endOfDay()))
            ->whereHas('bookings', fn (Builder $q) => $q->where('status', BookingStatus::Confirmed))
            ->pluck('starts_at');

        return $dates
            ->map(fn ($date) => Carbon::parse((string) $date)->format('Y-m'))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, User>
     */
    public function clientsForStats(?int $clientId = null): Collection
    {
        return User::query()
            ->where('role', UserRole::Client)
            ->when($clientId, fn (Builder $q) => $q->where('id', $clientId))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();
    }

    /**
     * @return Collection<int, ClassSession>
     */
    public function sessionsForAnalytics(?Carbon $from = null, ?Carbon $to = null): Collection
    {
        return ClassSession::query()
            ->when($from, fn (Builder $q) => $q->where('starts_at', '>=', $from->copy()->startOfDay()))
            ->when($to, fn (Builder $q) => $q->where('starts_at', '<=', $to->copy()->endOfDay()))
            ->with([
                'trainer:id,first_name,last_name',
                'bookings' => fn ($q) => $q
                    ->where('status', BookingStatus::Confirmed)
                    ->with('user:id,first_name,last_name,patronymic'),
            ])
            ->withCount(['bookings as confirmed_count' => fn ($q) => $q->where('status', BookingStatus::Confirmed)])
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * @return Collection<int, Booking>
     */
    public function completedVisits(?Carbon $from = null, ?Carbon $to = null, ?int $clientId = null): Collection
    {
        return $this->completedVisitsQuery($from, $to, $clientId)
            ->with([
                'user:id,first_name,last_name,patronymic,phone',
                'classSession:id,title,starts_at,type',
            ])
            ->orderBy('class_sessions.starts_at')
            ->get();
    }

    public function visitsCountForClientInMonth(User $client, string $monthKey): int
    {
        [$year, $month] = array_map('intval', explode('-', $monthKey));

        return Booking::query()
            ->where('user_id', $client->id)
            ->where('status', BookingStatus::Confirmed)
            ->whereHas('classSession', function (Builder $q) use ($year, $month) {
                $q->whereYear('starts_at', $year)
                    ->whereMonth('starts_at', $month)
                    ->where('starts_at', '<', now());
            })
            ->count();
    }

    /**
     * @return Builder<Booking>
     */
    private function completedVisitsQuery(?Carbon $from = null, ?Carbon $to = null, ?int $clientId = null): Builder
    {
        return Booking::query()
            ->where('bookings.status', BookingStatus::Confirmed)
            ->when($clientId, fn (Builder $q) => $q->where('user_id', $clientId))
            ->whereHas('classSession', function (Builder $q) use ($from, $to) {
                $q->where('starts_at', '<', now())
                    ->when($from, fn (Builder $inner) => $inner->where('starts_at', '>=', $from->copy()->startOfDay()))
                    ->when($to, fn (Builder $inner) => $inner->where('starts_at', '<=', $to->copy()->endOfDay()));
            })
            ->join('class_sessions', 'bookings.class_session_id', '=', 'class_sessions.id')
            ->select('bookings.*');
    }
}
