<?php

namespace App\Http\Controllers;

use App\Enums\BookingStatus;
use App\Services\SubscriptionService;
use App\Support\OfferStorage;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function __invoke(SubscriptionService $subscriptions): View
    {
        $user = auth()->user();

        $allSubscriptions = $user->subscriptions()
            ->orderByDesc('ends_at')
            ->get();

        $upcomingBookings = $user->bookings()
            ->upcoming()
            ->with(['classSession.trainer', 'subscription'])
            ->get()
            ->sortBy(fn ($b) => $b->classSession->starts_at)
            ->values();

        $history = $user->subscriptions()
            ->with(['usages' => fn ($q) => $q->orderByDesc('used_at')])
            ->get()
            ->flatMap(fn ($sub) => $sub->usages->map(fn ($usage) => [
                'sort' => $usage->used_at,
                'date' => $usage->used_at->translatedFormat('d F Y'),
                'title' => $usage->description ?? 'Занятие',
                'sub' => $sub->type->shortLabel().' абонемент',
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
                'date' => $booking->classSession->starts_at->translatedFormat('D, j F').' · '.$booking->classSession->formattedTime(),
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
        ]);
    }
}
