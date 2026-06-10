<?php

namespace App\Console\Commands;

use App\Services\StudioMailingService;
use Illuminate\Console\Command;

class SendDailyBookingReminders extends Command
{
    protected $signature = 'studio:daily-booking-reminders {--dry-run : Показать, что будет отправлено, без отправки}';

    protected $description = 'Ежедневная рассылка в 20:00: напоминание о занятиях на завтра или сообщение об их отсутствии';

    public function handle(StudioMailingService $mailings): int
    {
        if (! (config('studio.mailings.daily_reminder.enabled') ?? true)) {
            $this->info('Ежедневная рассылка отключена в настройках.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $counts = $mailings->sendDailyReminders(dryRun: $dryRun);

        $this->info(sprintf(
            '%sЕжедневная рассылка: с занятиями — %d, без занятий — %d, пропущено (уже отправлено) — %d.',
            $dryRun ? '[dry-run] ' : '',
            $counts['with_bookings'],
            $counts['without_bookings'],
            $counts['skipped'],
        ));

        return self::SUCCESS;
    }
}
