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

// Отложенное списание по броням на абонементы с будущей датой начала.
Schedule::command('studio:charge-pending-bookings')
    ->dailyAt('00:05')
    ->withoutOverlapping();

// Ежедневное напоминание о занятиях на завтра — в 20:00.
Schedule::command('studio:daily-booking-reminders')
    ->dailyAt((string) config('studio.mailings.daily_reminder.time', '20:00'))
    ->withoutOverlapping();

// Рассылка об открытии записи на новую неделю — воскресенье в 14:00.
Schedule::command('studio:weekly-schedule-announcement')
    ->weeklyOn(0, (string) config('studio.mailings.weekly_schedule.time', '14:00'))
    ->withoutOverlapping();

// Поздравления с днём рождения — каждый день утром.
Schedule::command('studio:birthday-greetings')
    ->dailyAt((string) config('studio.mailings.birthday.time', '09:00'))
    ->withoutOverlapping();

// Уведомления о новостях с отложенной датой публикации.
Schedule::command('studio:publish-scheduled-news')
    ->everyFiveMinutes()
    ->withoutOverlapping();
