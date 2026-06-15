<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\SubscriptionType;
use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Subscription;
use App\Models\User;
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
            ->with('user:id,first_name,last_name,patronymic,phone')
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return list<string>
     */
    public function visitMonths(?Carbon $from = null, ?Carbon $to = null): array
    {
        return Booking::query()
            ->where('bookings.status', BookingStatus::Confirmed)
            ->whereHas('classSession', function (Builder $q) use ($from, $to) {
                $q->where('starts_at', '<', now())
                    ->when($from, fn (Builder $inner) => $inner->where('starts_at', '>=', $from->copy()->startOfDay()))
                    ->when($to, fn (Builder $inner) => $inner->where('starts_at', '<=', $to->copy()->endOfDay()));
            })
            ->join('class_sessions', 'bookings.class_session_id', '=', 'class_sessions.id')
            ->selectRaw('DATE_FORMAT(class_sessions.starts_at, "%Y-%m") as month_key')
            ->distinct()
            ->orderBy('month_key')
            ->pluck('month_key')
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
