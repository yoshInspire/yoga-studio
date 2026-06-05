<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class AccountController extends Controller
{
    public function __invoke(): View
    {
        $user = auth()->user();

        $subs = [
            ['name' => 'Групповые занятия', 'type' => 'group', 'left' => 6, 'total' => 8, 'start' => '20 мая 2026', 'end' => '20 июля 2026'],
            ['name' => 'Индивидуальные занятия', 'type' => 'indiv', 'left' => 2, 'total' => 4, 'start' => '01 июня 2026', 'end' => '01 августа 2026'],
        ];
        $bookings = [
            ['date' => 'Ср, 5 июня', 'time' => '08:00', 'title' => 'Хатха-йога', 'trainer' => 'Ирина Коленцева', 'type' => 'Групповое'],
            ['date' => 'Пт, 7 июня', 'time' => '12:00', 'title' => 'Индивидуальное занятие', 'trainer' => 'Ирина Коленцева', 'type' => 'Индивидуальное'],
        ];
        $history = [
            ['date' => '28 мая 2026', 'title' => 'Инь-йога', 'sub' => 'Групповой абонемент'],
            ['date' => '24 мая 2026', 'title' => 'Здоровая спина', 'sub' => 'Групповой абонемент'],
            ['date' => '21 мая 2026', 'title' => 'Индивидуальное занятие', 'sub' => 'Индивидуальный абонемент'],
        ];
        $cancelled = [
            ['date' => 'Пт, 7 июня · 18:00', 'title' => 'Инь-йога', 'reason' => 'Недостаточное количество участников в группе'],
        ];

        return view('pages.account', compact('user', 'subs', 'bookings', 'history', 'cancelled'));
    }
}
