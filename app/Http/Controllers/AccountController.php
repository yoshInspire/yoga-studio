<?php

namespace App\Http\Controllers;

use App\Services\SubscriptionService;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function __invoke(SubscriptionService $subscriptions): View
    {
        $user = auth()->user();

        $activeSubscriptions = $subscriptions->activeForUser($user);
        $allSubscriptions = $user->subscriptions()
            ->orderByDesc('ends_at')
            ->get();

        // Записи, история и отмены — демо до блока 5.
        $bookings = [
            ['date' => 'Ср, 5 июня', 'time' => '08:00', 'title' => 'Хатха-йога', 'trainer' => 'Ирина Коленцева', 'type' => 'Групповое'],
            ['date' => 'Пт, 7 июня', 'time' => '12:00', 'title' => 'Индивидуальное занятие', 'trainer' => 'Ирина Коленцева', 'type' => 'Индивидуальное'],
        ];
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
        $cancelled = [
            ['date' => 'Пт, 7 июня · 18:00', 'title' => 'Инь-йога', 'reason' => 'Недостаточное количество участников в группе'],
        ];

        return view('pages.account', [
            'user' => $user,
            'subscriptions' => $allSubscriptions,
            'activeSubscriptions' => $activeSubscriptions,
            'bookings' => $bookings,
            'history' => $history,
            'cancelled' => $cancelled,
        ]);
    }
}
