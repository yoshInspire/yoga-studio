<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\BookingStatus;
use App\Enums\SubscriptionType;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Direction;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Сводка и справочники для админских форм приложения.
 */
class OverviewController extends Controller
{
    /** Сводка. */
    public function overview(): JsonResponse
    {
        $today = now()->startOfDay();

        return response()->json([
            'clients' => User::query()->where('role', UserRole::Client)->count(),
            'trainers' => User::query()->where('role', UserRole::Trainer)->count(),
            'sessions_today' => ClassSession::query()->whereDate('starts_at', $today)->count(),
            'active_subscriptions' => Subscription::query()->active()->count(),
            'bookings_upcoming' => Booking::query()
                ->where('status', BookingStatus::Confirmed)
                ->whereHas('classSession', fn ($q) => $q->where('starts_at', '>=', now()))
                ->count(),
        ]);
    }

    /** Справочники для форм (направления, тренеры, типы занятий). */
    public function meta(): JsonResponse
    {
        return response()->json([
            'directions' => Direction::query()->ordered()->get(['id', 'title'])
                ->map(fn ($d) => ['id' => $d->id, 'title' => $d->title]),
            'trainers' => User::query()->where('role', UserRole::Trainer)->orderBy('last_name')->get()
                ->map(fn ($t) => ['id' => $t->id, 'name' => $t->shortName()]),
            // Длительность и число мест зависят от типа занятия — те же
            // значения подставляет форма в веб-админке.
            'types' => collect(SubscriptionType::cases())->map(fn (SubscriptionType $t) => [
                'value' => $t->value,
                'label' => $t->label(),
                'default_duration' => ClassSession::defaultDurationMinutesForType($t),
                'default_capacity' => $t === SubscriptionType::Individual
                    ? 1
                    : (int) config('studio.default_class_capacity', 6),
            ]),
            'topic_max_length' => (int) config('studio.class_title_max_length', 120),
        ]);
    }
}
