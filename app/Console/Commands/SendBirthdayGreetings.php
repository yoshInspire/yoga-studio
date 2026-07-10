<?php

namespace App\Console\Commands;

use App\Services\StudioMailingService;
use Illuminate\Console\Command;

class SendBirthdayGreetings extends Command
{
    protected $signature = 'studio:birthday-greetings';

    protected $description = 'Поздравления клиентов с днём рождения (email и/или Telegram)';

    public function handle(StudioMailingService $mailings): int
    {
        if (! (config('studio.mailings.birthday.enabled') ?? true)) {
            $this->info('Поздравления с днём рождения отключены в настройках.');

            return self::SUCCESS;
        }

        $counts = $mailings->sendBirthdayGreetings();

        $this->info(sprintf(
            'Поздравления с днём рождения: отправлено — %d, пропущено (уже отправлено сегодня) — %d.',
            $counts['sent'],
            $counts['skipped'],
        ));

        return self::SUCCESS;
    }
}
