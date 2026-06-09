<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Enums\ClassSessionStatus;
use App\Enums\SubscriptionType;
use App\Models\ClassSession;
use App\Services\BookingService;
use App\Services\NotificationService;
use Illuminate\Console\Command;

class CancelUnderfilledClasses extends Command
{
    protected $signature = 'studio:cancel-underfilled {--dry-run : Показать, что будет отменено, без изменений}';

    protected $description = 'Автоотмена групповых занятий при недоборе группы с уведомлением клиентов и администратора';

    public function handle(BookingService $bookings, NotificationService $notifications): int
    {
        $config = config('studio.auto_cancel');

        if (! ($config['enabled'] ?? true)) {
            $this->info('Автоотмена отключена в настройках (studio.auto_cancel.enabled).');

            return self::SUCCESS;
        }

        $minGroupSize = (int) ($config['min_group_size'] ?? 2);
        $dryRun = (bool) $this->option('dry-run');
        $now = now();

        $candidates = ClassSession::query()
            ->where('status', ClassSessionStatus::Scheduled)
            ->where('type', SubscriptionType::Group)
            ->where('starts_at', '>', $now)
            ->withCount(['bookings as confirmed_count' => fn ($q) => $q->where('status', BookingStatus::Confirmed)])
            ->orderBy('starts_at')
            ->get();

        $cancelledCount = 0;

        foreach ($candidates as $session) {
            $confirmed = (int) $session->confirmed_count;

            // Отменяем только реально недобранные группы, где кто-то записан (1..min-1).
            if ($confirmed < 1 || $confirmed >= $minGroupSize) {
                continue;
            }

            if ($now->lt($session->autoCancelCheckpoint())) {
                continue;
            }

            $affected = $session->bookings()
                ->where('status', BookingStatus::Confirmed)
                ->with('user')
                ->get();

            $reason = 'Занятие отменено автоматически: группа не набралась (меньше '
                .$minGroupSize.' человек).';

            $this->line(sprintf(
                '%s «%s» — записано %d, отмена%s',
                $session->starts_at->format('d.m.Y H:i'),
                $session->title,
                $confirmed,
                $dryRun ? ' (dry-run)' : '',
            ));

            if ($dryRun) {
                continue;
            }

            $bookings->cancelClass($session, $reason);
            $cancelledCount++;

            $this->notifyClients($notifications, $session, $affected, $minGroupSize);
            $this->notifyAdmin($notifications, $session, $affected, $minGroupSize);
        }

        $this->info($dryRun
            ? 'Проверка завершена (dry-run).'
            : "Автоотмена завершена. Отменено занятий: {$cancelledCount}.");

        return self::SUCCESS;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \App\Models\Booking>  $affected
     */
    private function notifyClients(NotificationService $notifications, ClassSession $session, $affected, int $minGroupSize): void
    {
        foreach ($affected as $booking) {
            $user = $booking->user;

            if ($user === null) {
                continue;
            }

            $notifications->notifyUser(
                $user,
                'Занятие отменено',
                [
                    'Здравствуйте, '.$user->first_name.'!',
                    'К сожалению, занятие «'.$session->title.'» '.$session->formattedDateTime()
                        .' отменено: группа не набралась (меньше '.$minGroupSize.' человек).',
                    'Занятие возвращено на ваш абонемент — вы можете записаться на другое время.',
                ],
                subject: 'Занятие отменено',
            );
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \App\Models\Booking>  $affected
     */
    private function notifyAdmin(NotificationService $notifications, ClassSession $session, $affected, int $minGroupSize): void
    {
        $names = $affected
            ->map(fn ($booking) => $booking->user?->fullName() ?? 'Клиент #'.$booking->user_id)
            ->all();

        $notifications->notifyAdmin(
            'Автоотмена занятия',
            [
                'Занятие «'.$session->title.'» '.$session->formattedDateTime().' отменено автоматически.',
                'Причина: группа не набралась (записано '.count($names).', нужно от '.$minGroupSize.').',
                'Клиенты (записи аннулированы, занятия возвращены на абонемент): '.implode(', ', $names).'.',
            ],
            subject: 'Автоотмена занятия',
        );
    }
}
