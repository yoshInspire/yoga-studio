<?php

namespace App\Http\Controllers\Api;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\FamilyService;
use App\Support\OfferStorage;
use App\Support\RussianDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    /** Данные личного кабинета клиента. */
    public function show(Request $request, FamilyService $family): JsonResponse
    {
        $user = $request->user();

        $subscriptions = $user->subscriptions()
            ->with(['sharedWith', 'paymentItem.payment.user'])
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
                // Кому из семьи открыт абонемент — подпись на билете.
                'shared_with' => $s->sharedWith->map(fn ($u) => $u->shortName())->values(),
                // Подарок от члена семьи — «Подарок от Анны».
                'gift_from' => $s->giftFrom()?->shortName(),
            ]);

        $present = fn ($b) => [
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
                // Запись по абонементу семьи: чей абонемент — бирка в списке.
                'family_owner' => $b->subscription !== null && $b->subscription->user_id !== $user->id
                    ? $b->subscription->user?->shortName()
                    : null,
                'can_cancel' => $b->canBeCancelledByClient(),
                'can_reschedule' => $b->canBeRescheduledByClient(),
                // Записал член семьи — «Записала Анна».
                'booked_by_name' => $b->booked_by_id !== null && $b->booked_by_id !== $user->id
                    ? $b->bookedBy?->shortName()
                    : null,
            ];

        $bookings = $user->bookings()
            ->upcoming()
            ->with(['classSession.trainer', 'classSession.direction', 'subscription.user', 'bookedBy'])
            ->get()
            ->sortBy(fn ($b) => $b->classSession->starts_at)
            ->values()
            ->map($present);

        // Записи, которыми я управляю: сделанные мной за членов семьи и записи
        // моих детей. `for_name` — кто идёт; отменить можно отсюда же.
        $familyBookings = $family->managedBookings($user)
            ->map(fn ($b) => [...$present($b), 'for_user_id' => $b->user_id, 'for_name' => $b->user->shortName()])
            ->values();

        $history = $user->subscriptions()
            ->with(['usages' => fn ($q) => $q->orderByDesc('used_at')])
            ->get()
            ->flatMap(fn ($sub) => $sub->usages->map(fn ($usage) => [
                'sort' => $usage->used_at->timestamp,
                'date' => RussianDate::dayMonthYear($usage->used_at),
                'title' => $usage->description ?? 'Занятие',
                'sub' => $sub->type->shortLabel(),
            ]))
            ->concat(collect($family->sharedUsageHistory($user))->map(fn (array $row) => [
                'sort' => $row['used_at']->timestamp,
                'date' => RussianDate::dayMonthYear($row['used_at']),
                'title' => $row['title'],
                'sub' => 'Абонемент семьи · '.$row['owner_name'],
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
            'family_bookings' => $familyBookings,
            'history' => $history,
            'cancelled' => $cancelled,
            'offer_available' => OfferStorage::exists(),
            'offer_accepted' => $user->hasAcceptedOffer(),
            'family' => $this->familySummary($user, $family),
        ]);
    }

    /**
     * Коротко про семью — для строки «Семья» и раздела «Абонементы семьи» в
     * кабинете. Подробности (состав, приглашения) отдаёт `/family`.
     *
     * @return array<string, mixed>
     */
    private function familySummary(User $user, FamilyService $family): array
    {
        $membership = $family->membershipOf($user);

        return [
            'in_group' => $membership !== null,
            'is_owner' => $membership?->isOwner() ?? false,
            'members_count' => $membership?->group->members()->count() ?? 0,
            // Кого можно записать на занятие вместе с собой (блок Б фазы 2).
            'relatives' => $family->bookableRelatives($user),
            'shared_with_me' => $family->subscriptionsSharedWith($user)
                ->map(fn ($s) => $family->presentSharedSubscription($s, $user))
                ->values(),
        ];
    }
}
