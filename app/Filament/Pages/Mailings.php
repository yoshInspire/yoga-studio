<?php

namespace App\Filament\Pages;

use App\Services\BirthdayGreetingService;
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
use InvalidArgumentException;

class Mailings extends Page
{
    public string $customHeading = '';

    public string $customBody = '';

    public string $birthdayGreeting1 = '';

    public string $birthdayGreeting2 = '';

    public string $birthdayGreeting3 = '';

    public string $birthdayGreeting4 = '';

    public string $birthdayGreeting5 = '';

    protected static ?string $navigationLabel = 'Рассылки';

    protected static ?string $title = 'Рассылки клиентам';

    protected static ?int $navigationSort = 7;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    public function mount(): void
    {
        $bodies = app(BirthdayGreetingService::class)->orderedBodies();

        $this->birthdayGreeting1 = $bodies[0] ?? '';
        $this->birthdayGreeting2 = $bodies[1] ?? '';
        $this->birthdayGreeting3 = $bodies[2] ?? '';
        $this->birthdayGreeting4 = $bodies[3] ?? '';
        $this->birthdayGreeting5 = $bodies[4] ?? '';
    }

    public function content(Schema $schema): Schema
    {
        $mailings = app(StudioMailingService::class);
        [$weekStart, $weekEnd] = $mailings->announcementWeekRange();
        $recipients = $mailings->eligibleClientsCount();

        return $schema->components([
            Section::make('Поздравления с днём рождения')
                ->description('Автоматически каждый день в '
                    .(config('studio.mailings.birthday.time') ?? '09:00')
                    .'. Клиентам с днём рождения в этот день отправляется один из пяти вариантов текста (email и/или Telegram). Каждый следующий год — следующий вариант по кругу.')
                ->schema([
                    Textarea::make('birthdayGreeting1')
                        ->label('Вариант 1')
                        ->rows(4)
                        ->required()
                        ->maxLength(2000),
                    Textarea::make('birthdayGreeting2')
                        ->label('Вариант 2')
                        ->rows(4)
                        ->required()
                        ->maxLength(2000),
                    Textarea::make('birthdayGreeting3')
                        ->label('Вариант 3')
                        ->rows(4)
                        ->required()
                        ->maxLength(2000),
                    Textarea::make('birthdayGreeting4')
                        ->label('Вариант 4')
                        ->rows(4)
                        ->required()
                        ->maxLength(2000),
                    Textarea::make('birthdayGreeting5')
                        ->label('Вариант 5')
                        ->rows(4)
                        ->required()
                        ->maxLength(2000),
                    Actions::make([
                        Action::make('saveBirthdayGreetings')
                            ->label('Сохранить тексты')
                            ->icon(Heroicon::OutlinedBookmarkSquare)
                            ->color('success')
                            ->action(function () {
                                $this->saveBirthdayGreetings();
                            }),
                        Action::make('dryRunBirthday')
                            ->label('Проверить сегодня (dry-run)')
                            ->icon(Heroicon::OutlinedEye)
                            ->action(function () {
                                $this->runBirthday(dryRun: true);
                            }),
                    ]),
                ]),
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

    private function saveBirthdayGreetings(): void
    {
        $this->validate([
            'birthdayGreeting1' => ['required', 'string', 'max:2000'],
            'birthdayGreeting2' => ['required', 'string', 'max:2000'],
            'birthdayGreeting3' => ['required', 'string', 'max:2000'],
            'birthdayGreeting4' => ['required', 'string', 'max:2000'],
            'birthdayGreeting5' => ['required', 'string', 'max:2000'],
        ], [
            'birthdayGreeting1.required' => 'Заполните вариант 1.',
            'birthdayGreeting2.required' => 'Заполните вариант 2.',
            'birthdayGreeting3.required' => 'Заполните вариант 3.',
            'birthdayGreeting4.required' => 'Заполните вариант 4.',
            'birthdayGreeting5.required' => 'Заполните вариант 5.',
        ]);

        try {
            app(BirthdayGreetingService::class)->syncBodies([
                $this->birthdayGreeting1,
                $this->birthdayGreeting2,
                $this->birthdayGreeting3,
                $this->birthdayGreeting4,
                $this->birthdayGreeting5,
            ]);
        } catch (InvalidArgumentException $e) {
            Notification::make()
                ->title('Не удалось сохранить')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title('Тексты поздравлений сохранены')
            ->success()
            ->send();
    }

    private function runBirthday(bool $dryRun): void
    {
        $counts = app(StudioMailingService::class)->sendBirthdayGreetings(dryRun: $dryRun);

        Notification::make()
            ->title($dryRun ? 'Проверка поздравлений' : 'Поздравления отправлены')
            ->body(sprintf(
                'Сегодня именинников: %d. Пропущено (уже отправлено): %d.',
                $counts['sent'],
                $counts['skipped'],
            ))
            ->success()
            ->send();
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
