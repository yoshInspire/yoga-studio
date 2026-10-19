<?php

namespace App\Http\Controllers;

use App\Enums\BookingStatus;
use App\Services\FamilyService;
use App\Services\ProfileEmailVerificationService;
use App\Services\SubscriptionService;
use App\Services\TelegramAuthService;
use App\Support\OfferStorage;
use App\Support\RussianDate;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function __invoke(
        SubscriptionService $subscriptions,
        TelegramAuthService $telegram,
        ProfileEmailVerificationService $profileEmailVerification,
        FamilyService $family,
    ): View
    {
        $user = auth()->user();

        $allSubscriptions = $user->subscriptions()
            ->with('sharedWith')
            ->orderByDesc('ends_at')
            ->get();

        $upcomingBookings = $user->bookings()
            ->upcoming()
            ->with(['classSession.trainer', 'classSession.direction', 'subscription.user'])
            ->get()
            ->sortBy(fn ($b) => $b->classSession->starts_at)
            ->values();

        $history = $user->subscriptions()
            ->with(['usages' => fn ($q) => $q->orderByDesc('used_at')])
            ->get()
            ->flatMap(fn ($sub) => $sub->usages->map(fn ($usage) => [
                'sort' => $usage->used_at,
                'date' => RussianDate::dayMonthYear($usage->used_at),
                'title' => $usage->description ?? 'Занятие',
                'sub' => $sub->type->shortLabel().' абонемент',
            ]))
            // Посещения по абонементам семьи: списания лежат на чужих абонементах.
            ->concat(collect($family->sharedUsageHistory($user))->map(fn (array $row) => [
                'sort' => $row['used_at'],
                'date' => RussianDate::dayMonthYear($row['used_at']),
                'title' => $row['title'],
                'sub' => 'Абонемент семьи · '.$row['owner_name'],
            ]))
            ->sortByDesc('sort')
            ->values()
            ->map(fn (array $row) => collect($row)->except('sort')->all())
            ->all();

        $cancelled = $user->bookings()
            ->whereIn('status', [
                BookingStatus::ClassCancelled,
                BookingStatus::CancelledByAdmin,
            ])
            ->with('classSession')
            ->orderByDesc('cancelled_at')
            ->get()
            ->map(fn ($booking) => [
                'date' => RussianDate::weekdayShortDayMonth($booking->classSession->starts_at).' · '.$booking->classSession->formattedTime(),
                'title' => $booking->classSession->title,
                'reason' => $booking->cancellation_reason ?? $booking->classSession->cancellation_reason ?? 'Занятие отменено',
            ])
            ->all();

        return view('pages.account', [
            'user' => $user,
            'subscriptions' => $allSubscriptions,
            'bookings' => $upcomingBookings,
            'history' => $history,
            'cancelled' => $cancelled,
            'offerAvailable' => OfferStorage::exists(),
            'telegramEnabled' => $telegram->isEnabled(),
            'telegramBotUsername' => $telegram->botUsername(),
            'profileEditOpen' => session('profile_edit_open', false),
            'profileCodeSent' => session('profile_code_sent', false),
            'profileEmailVerified' => session('profile_email_verified') ?? $profileEmailVerification->verifiedEmail($user),
            'profilePendingEmail' => $profileEmailVerification->pendingEmail($user),
            'lkSection' => session('lk_section'),
            'family' => $family->overview($user),
            // Приглашение, открытое до входа: напоминаем о нём в кабинете.
            'pendingFamilyInvite' => ($code = session(FamilyController::PENDING_INVITE_KEY)) !== null
                ? $family->findInvite($code)
                : null,
        ]);
    }
}
