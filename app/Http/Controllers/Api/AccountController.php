<?php

namespace App\Http\Controllers\Api;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Support\OfferStorage;
use App\Support\RussianDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    /** Данные личного кабинета клиента. */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        $subscriptions = $user->subscriptions()
            ->orderByDesc('ends_at')
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'type' => $s->type->value,
                'type_label' => $s->type->label(),
                'type_short' => $s->type->shortLabel(),
                'sessions_total' => $s->sessions_total,
                'sessions_used' => $s->sessions_used,
                'sessions_remaining' => $s->sessionsRemaining(),
                'sessions_per_day' => $s->sessionsPerDay(),
                'starts_at' => $s->formattedStartsAt(),
                'ends_at' => $s->formattedEndsAt(),
                'days_until_end' => $s->daysUntilEnd(),
                'is_active' => $s->isActive(),
                'has_started' => $s->hasStarted(),
            ]);

        $bookings = $user->bookings()
            ->upcoming()
            ->with(['classSession.trainer', 'classSession.direction', 'subscription'])
            ->get()
            ->sortBy(fn ($b) => $b->classSession->starts_at)
            ->values()
            ->map(fn ($b) => [
                'id' => $b->id,
                'session_id' => $b->class_session_id,
                'title' => $b->classSession->title,
                'direction' => $b->classSession->direction?->title,
                'direction_slug' => $b->classSession->direction?->slug,
                'topic' => $b->classSession->topic,
                'trainer' => $b->classSession->trainerName(),
                'type' => $b->classSession->type->badgeClass(),
                'date_time' => $b->classSession->formattedDateTime(),
                'time_range' => $b->classSession->formattedTimeRange(),
                'subscription' => $b->subscription?->type->shortLabel(),
                'can_cancel' => $b->canBeCancelledByClient(),
                'can_reschedule' => $b->canBeRescheduledByClient(),
            ]);

        $history = $user->subscriptions()
            ->with(['usages' => fn ($q) => $q->orderByDesc('used_at')])
            ->get()
            ->flatMap(fn ($sub) => $sub->usages->map(fn ($usage) => [
                'sort' => $usage->used_at->timestamp,
                'date' => RussianDate::dayMonthYear($usage->used_at),
                'title' => $usage->description ?? 'Занятие',
                'sub' => $sub->type->shortLabel(),
            ]))
            ->sortByDesc('sort')
            ->values()
            ->map(fn ($row) => collect($row)->except('sort')->all());

        $cancelled = $user->bookings()
            ->whereIn('status', [BookingStatus::ClassCancelled, BookingStatus::CancelledByAdmin])
            ->with('classSession')
            ->orderByDesc('cancelled_at')
            ->get()
            ->map(fn ($b) => [
                'date' => RussianDate::weekdayShortDayMonth($b->classSession->starts_at).' · '.$b->classSession->formattedTime(),
                'title' => $b->classSession->title,
                'reason' => $b->cancellation_reason ?? $b->classSession->cancellation_reason ?? 'Занятие отменено',
            ]);

        return response()->json([
            'subscriptions' => $subscriptions,
            'bookings' => $bookings,
            'history' => $history,
            'cancelled' => $cancelled,
            'offer_available' => OfferStorage::exists(),
            'offer_accepted' => $user->hasAcceptedOffer(),
        ]);
    }
}
