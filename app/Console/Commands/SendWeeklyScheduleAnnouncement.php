<?php

namespace App\Console\Commands;

use App\Services\StudioMailingService;
use Illuminate\Console\Command;

class SendWeeklyScheduleAnnouncement extends Command
{
    protected $signature = 'studio:weekly-schedule-announcement {--dry-run : Показать, что будет отправлено, без отправки} {--force : Отправить повторно, даже если уже отправляли на эту неделю}';

    protected $description = 'Рассылка об открытии записи на новую неделю (воскресенье 14:00 или вручную)';

    public function handle(StudioMailingService $mailings): int
    {
        if (! (config('studio.mailings.weekly_schedule.enabled') ?? true)) {
            $this->info('Рассылка об открытии недели отключена в настройках.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $result = $mailings->sendWeeklyScheduleAnnouncement(dryRun: $dryRun, force: $force);

        $this->info(sprintf(
            '%sРассылка «открыта запись» (%s — %s): отправлено %d, пропущено %d.',
            $dryRun ? '[dry-run] ' : '',
            $result['from'],
            $result['to'],
            $result['sent'],
            $result['skipped'],
        ));

        return self::SUCCESS;
    }
}
