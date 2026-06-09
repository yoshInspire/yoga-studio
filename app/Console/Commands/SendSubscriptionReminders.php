<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Services\NotificationService;
use Illuminate\Console\Command;

class SendSubscriptionReminders extends Command
{
    protected $signature = 'studio:subscription-reminders {--dry-run : Показать, что будет отправлено, без отправки}';

    protected $description = 'Напоминания клиентам: остался 1 абонемент и заканчивается срок действия';

    public function handle(NotificationService $notifications): int
    {
        $reminders = config('studio.reminders');
        $lowThreshold = (int) ($reminders['low_sessions_threshold'] ?? 1);
        $expiringDays = (int) ($reminders['expiring_days_threshold'] ?? 5);
        $dryRun = (bool) $this->option('dry-run');

        $lowSent = $this->sendLowSessions($notifications, $lowThreshold, $dryRun);
        $expiringSent = $this->sendExpiring($notifications, $expiringDays, $dryRun);

        $this->info(sprintf(
            '%sНапоминаний «остаётся занятие»: %d, «заканчивается срок»: %d.',
            $dryRun ? '[dry-run] ' : '',
            $lowSent,
            $expiringSent,
        ));

        return self::SUCCESS;
    }

    private function sendLowSessions(NotificationService $notifications, int $threshold, bool $dryRun): int
    {
        $sent = 0;

        Subscription::query()
            ->active()
            ->whereNull('low_sessions_notified_at')
            ->whereRaw('(sessions_total - sessions_used) <= ?', [$threshold])
            ->with('user')
            ->each(function (Subscription $subscription) use ($notifications, $dryRun, &$sent) {
                $user = $subscription->user;

                if ($user === null) {
                    return;
                }

                $remaining = $subscription->sessionsRemaining();

                $this->line('Остаётся '.$remaining.' занятие · '.$subscription->type->shortLabel()
                    .' · '.$user->fullName());

                if ($dryRun) {
                    $sent++;

                    return;
                }

                $notifications->notifyUser(
                    $user,
                    'Абонемент заканчивается',
                    [
                        'Здравствуйте, '.$user->first_name.'!',
                        'В вашем абонементе ('.$subscription->type->shortLabel().') осталось '
                            .$remaining.' занятие.',
                        'Чтобы не прерывать практику, не забудьте приобрести новый абонемент.',
                    ],
                    subject: 'Абонемент заканчивается',
                );

                $subscription->update(['low_sessions_notified_at' => now()]);
                $sent++;
            });

        return $sent;
    }

    private function sendExpiring(NotificationService $notifications, int $daysThreshold, bool $dryRun): int
    {
        $sent = 0;
        $today = now()->startOfDay();
        $limitDate = $today->copy()->addDays($daysThreshold)->toDateString();

        Subscription::query()
            ->active()
            ->whereNull('expiring_notified_at')
            ->whereDate('ends_at', '<=', $limitDate)
            ->whereDate('ends_at', '>=', $today->toDateString())
            ->with('user')
            ->each(function (Subscription $subscription) use ($notifications, $dryRun, &$sent) {
                $user = $subscription->user;

                if ($user === null) {
                    return;
                }

                $days = max(0, $subscription->daysUntilEnd());
                $remaining = $subscription->sessionsRemaining();

                $this->line('Заканчивается через '.$days.' дн. · остаток '.$remaining
                    .' · '.$user->fullName());

                if ($dryRun) {
                    $sent++;

                    return;
                }

                $notifications->notifyUser(
                    $user,
                    'Срок абонемента заканчивается',
                    [
                        'Здравствуйте, '.$user->first_name.'!',
                        'До окончания вашего абонемента ('.$subscription->type->shortLabel().') осталось '
                            .$days.' '.$this->daysWord($days).' (до '.$subscription->formattedEndsAt().').',
                        'Неиспользованных занятий: '.$remaining.'.',
                        'Успейте использовать или продлите абонемент в студии.',
                    ],
                    subject: 'Срок абонемента заканчивается',
                );

                $subscription->update(['expiring_notified_at' => now()]);
                $sent++;
            });

        return $sent;
    }

    private function daysWord(int $days): string
    {
        $mod100 = $days % 100;
        $mod10 = $days % 10;

        if ($mod100 >= 11 && $mod100 <= 14) {
            return 'дней';
        }

        return match ($mod10) {
            1 => 'день',
            2, 3, 4 => 'дня',
            default => 'дней',
        };
    }
}
