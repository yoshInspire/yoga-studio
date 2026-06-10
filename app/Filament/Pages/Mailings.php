<?php

namespace App\Filament\Pages;

use App\Services\StudioMailingService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class Mailings extends Page
{
    protected static ?string $navigationLabel = 'Рассылки';

    protected static ?string $title = 'Рассылки клиентам';

    protected static ?int $navigationSort = 7;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    public function content(Schema $schema): Schema
    {
        $mailings = app(StudioMailingService::class);
        [$weekStart, $weekEnd] = $mailings->announcementWeekRange();

        return $schema->components([
            Section::make('Ежедневное напоминание')
                ->description('Автоматически каждый день в '
                    .(config('studio.mailings.daily_reminder.time') ?? '20:00')
                    .'. Клиентам с записью на завтра — список занятий и ссылка в личный кабинет. Остальным — короткое сообщение без занятий на завтра.')
                ->schema([
                    Actions::make([
                        Action::make('dryRunDaily')
                            ->label('Проверить (dry-run)')
                            ->icon(Heroicon::OutlinedEye)
                            ->action(function () {
                                $this->runDaily(dryRun: true);
                            }),
                    ]),
                ]),
            Section::make('Открытие записи на неделю')
                ->description('Автоматически по воскресеньям в '
                    .(config('studio.mailings.weekly_schedule.time') ?? '14:00')
                    .'. Период в тексте: '.$weekStart->translatedFormat('l, j F')
                    .' — '.$weekEnd->translatedFormat('l, j F').'.')
                ->schema([
                    Actions::make([
                        Action::make('sendWeekly')
                            ->label('Отправить сейчас')
                            ->icon(Heroicon::OutlinedPaperAirplane)
                            ->color('success')
                            ->requiresConfirmation()
                            ->modalHeading('Отправить рассылку об открытии записи?')
                            ->modalDescription('Сообщение уйдёт всем клиентам с принятой офертой (email и/или Telegram). Повторная отправка на эту же неделю будет пропущена.')
                            ->action(function () {
                                $this->runWeekly(dryRun: false, force: false);
                            }),
                        Action::make('forceWeekly')
                            ->label('Отправить повторно')
                            ->icon(Heroicon::OutlinedArrowPath)
                            ->color('warning')
                            ->requiresConfirmation()
                            ->modalHeading('Повторная рассылка')
                            ->modalDescription('Отправить снова всем клиентам, даже если рассылка на эту неделю уже была.')
                            ->action(function () {
                                $this->runWeekly(dryRun: false, force: true);
                            }),
                        Action::make('dryRunWeekly')
                            ->label('Проверить (dry-run)')
                            ->icon(Heroicon::OutlinedEye)
                            ->action(function () {
                                $this->runWeekly(dryRun: true, force: false);
                            }),
                    ]),
                ]),
        ]);
    }

    private function runDaily(bool $dryRun): void
    {
        $counts = app(StudioMailingService::class)->sendDailyReminders(dryRun: $dryRun);

        Notification::make()
            ->title($dryRun ? 'Проверка ежедневной рассылки' : 'Ежедневная рассылка отправлена')
            ->body(sprintf(
                'С занятиями: %d. Без занятий: %d. Пропущено: %d.',
                $counts['with_bookings'],
                $counts['without_bookings'],
                $counts['skipped'],
            ))
            ->success()
            ->send();
    }

    private function runWeekly(bool $dryRun, bool $force): void
    {
        $result = app(StudioMailingService::class)->sendWeeklyScheduleAnnouncement(dryRun: $dryRun, force: $force);

        Notification::make()
            ->title($dryRun ? 'Проверка недельной рассылки' : 'Рассылка об открытии записи отправлена')
            ->body(sprintf(
                'Период: %s — %s. Отправлено: %d. Пропущено: %d.',
                $result['from'],
                $result['to'],
                $result['sent'],
                $result['skipped'],
            ))
            ->success()
            ->send();
    }
}
