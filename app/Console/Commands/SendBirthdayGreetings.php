<?php

namespace App\Console\Commands;

use App\Services\StudioMailingService;
use Illuminate\Console\Command;

class SendBirthdayGreetings extends Command
{
    protected $signature = 'studio:birthday-greetings {--dry-run : Показать, кому будет отправлено, без отправки}';

    protected $description = 'Поздравления клиентов с днём рождения (email и/или Telegram)';

    public function handle(StudioMailingService $mailings): int
    {
        if (! (config('studio.mailings.birthday.enabled') ?? true)) {
            $this->info('Поздравления с днём рождения отключены в настройках.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $counts = $mailings->sendBirthdayGreetings(dryRun: $dryRun);

        $this->info(sprintf(
            '%sПоздравления с днём рождения: отправлено — %d, пропущено (уже отправлено в этом году) — %d.',
            $dryRun ? '[dry-run] ' : '',
            $counts['sent'],
            $counts['skipped'],
        ));

        return self::SUCCESS;
    }
}
