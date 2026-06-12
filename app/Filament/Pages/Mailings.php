<?php

namespace App\Filament\Pages;

use App\Services\StudioMailingService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class Mailings extends Page
{
    public string $customHeading = '';

    public string $customBody = '';

    protected static ?string $navigationLabel = 'Рассылки';

    protected static ?string $title = 'Рассылки клиентам';

    protected static ?int $navigationSort = 7;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    public function content(Schema $schema): Schema
    {
        $mailings = app(StudioMailingService::class);
        [$weekStart, $weekEnd] = $mailings->announcementWeekRange();
        $recipients = $mailings->eligibleClientsCount();

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
            Section::make('Произвольное оповещение')
                ->description('Свободная рассылка на любую тему. Сообщение уйдёт всем клиентам с принятой офертой (email и/или Telegram). Сейчас получателей: '.$recipients.'.')
                ->schema([
                    TextInput::make('customHeading')
                        ->label('Тема сообщения')
                        ->placeholder('Например: Изменение расписания на праздники')
                        ->required()
                        ->maxLength(120),
                    Textarea::make('customBody')
                        ->label('Текст')
                        ->placeholder("Здравствуйте!\n\nТекст вашего сообщения...\n\nДо встречи в студии!")
                        ->rows(8)
                        ->required()
                        ->maxLength(5000)
                        ->helperText('Каждый абзац — с новой строки. Тема станет заголовком письма и заголовком в Telegram.'),
                    Actions::make([
                        Action::make('sendCustom')
                            ->label('Отправить')
                            ->icon(Heroicon::OutlinedPaperAirplane)
                            ->color('success')
                            ->requiresConfirmation()
                            ->modalHeading('Отправить оповещение?')
                            ->modalDescription('Сообщение уйдёт всем клиентам с принятой офертой (email и/или Telegram).')
                            ->action(function () {
                                $this->runCustom(dryRun: false);
                            }),
                        Action::make('dryRunCustom')
                            ->label('Проверить (dry-run)')
                            ->icon(Heroicon::OutlinedEye)
                            ->action(function () {
                                $this->runCustom(dryRun: true);
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

    private function runCustom(bool $dryRun): void
    {
        $this->validate([
            'customHeading' => ['required', 'string', 'max:120'],
            'customBody' => ['required', 'string', 'max:5000'],
        ], [
            'customHeading.required' => 'Укажите тему сообщения.',
            'customBody.required' => 'Напишите текст сообщения.',
        ]);

        $result = app(StudioMailingService::class)->sendCustomAnnouncement(
            heading: $this->customHeading,
            body: $this->customBody,
            dryRun: $dryRun,
        );

        Notification::make()
            ->title($dryRun ? 'Проверка произвольного оповещения' : 'Оповещение отправлено')
            ->body(sprintf(
                'Тема: «%s». %s: %d.',
                $this->customHeading,
                $dryRun ? 'Будет отправлено' : 'Отправлено',
                $result['sent'],
            ))
            ->success()
            ->send();

        if (! $dryRun) {
            $this->customHeading = '';
            $this->customBody = '';
        }
    }
}
