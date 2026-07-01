<?php

namespace App\Console\Commands;

use App\Services\NewsNotificationService;
use Illuminate\Console\Command;

class PublishScheduledNews extends Command
{
    protected $signature = 'studio:publish-scheduled-news {--dry-run : Показать, что будет отправлено, без отправки}';

    protected $description = 'Отправить уведомления о новостях с наступившей датой публикации';

    public function handle(NewsNotificationService $notifications): int
    {
        if (! (config('studio.news_notifications.enabled') ?? true)) {
            $this->info('Уведомления о новостях отключены в настройках.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $pending = $notifications->pendingPublications();

        if ($pending->isEmpty()) {
            $this->info('Нет новостей, ожидающих рассылки.');

            return self::SUCCESS;
        }

        $totalRecipients = 0;

        foreach ($pending as $news) {
            if ($dryRun) {
                $this->line(sprintf('• «%s» (id %d)', $news->title, $news->id));
                continue;
            }

            $sent = $notifications->notifyClientsIfNeeded($news);
            $totalRecipients += $sent ?? 0;

            $this->line(sprintf(
                '«%s»: уведомлено %d клиентов.',
                $news->title,
                $sent ?? 0,
            ));
        }

        if ($dryRun) {
            $this->info(sprintf('[dry-run] К рассылке: %d новостей.', $pending->count()));
        } else {
            $this->info(sprintf('Обработано новостей: %d, всего получателей: %d.', $pending->count(), $totalRecipients));
        }

        return self::SUCCESS;
    }
}
