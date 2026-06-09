<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Автоотмена недобранных групп — проверяем часто, чтобы попасть в окна 15ч/5ч.
Schedule::command('studio:cancel-underfilled')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

// Напоминания по абонементам — раз в день утром.
Schedule::command('studio:subscription-reminders')
    ->dailyAt('10:00')
    ->withoutOverlapping();
