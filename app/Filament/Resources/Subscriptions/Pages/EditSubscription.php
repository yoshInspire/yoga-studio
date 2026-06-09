<?php

namespace App\Filament\Resources\Subscriptions\Pages;

use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Models\Subscription;
use App\Services\SubscriptionService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Carbon;

class EditSubscription extends EditRecord
{
    protected static string $resource = SubscriptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('extend')
                ->label('Продлить')
                ->icon('heroicon-o-calendar')
                ->form([
                    Select::make('preset_days')
                        ->label('Срок продления')
                        ->options([
                            'custom' => 'Другое количество дней',
                            '7' => '7 дней',
                            '14' => '14 дней',
                            '30' => '30 дней',
                            '60' => '60 дней',
                            '90' => '90 дней',
                        ])
                        ->default('30')
                        ->required()
                        ->live()
                        ->helperText(function (Subscription $record, Get $get): ?string {
                            $preset = $get('preset_days');

                            if ($preset === null || $preset === '' || $preset === 'custom') {
                                return null;
                            }

                            return $this->extensionPreview($record, (int) $preset);
                        })
                        ->afterStateUpdated(function (Set $set, ?string $state): void {
                            if ($state !== null && $state !== 'custom') {
                                $set('days', (int) $state);
                            }
                        }),
                    TextInput::make('days')
                        ->label('Продлить на (дней)')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(3650)
                        ->required()
                        ->default(30)
                        ->visible(fn (Get $get): bool => $get('preset_days') === 'custom')
                        ->helperText(fn (Subscription $record, Get $get): string => $this->extensionPreview($record, (int) $get('days'))),
                    Textarea::make('note')
                        ->label('Комментарий')
                        ->placeholder('Например: бесплатное продление, платное продление, перенос из-за болезни')
                        ->rows(2)
                        ->helperText('Сохранится в заметке абонемента вместе с датой и количеством дней.'),
                ])
                ->action(function (array $data, Subscription $record, SubscriptionService $service): void {
                    $days = (int) ($data['preset_days'] === 'custom'
                        ? $data['days']
                        : $data['preset_days']);

                    $service->extendByDays($record, $days, $data['note'] ?? null);

                    Notification::make()
                        ->title('Абонемент продлён')
                        ->body('Новая дата окончания: '.$record->fresh()->ends_at->format('d.m.Y'))
                        ->success()
                        ->send();

                    $this->refreshFormData(['ends_at', 'admin_note']);
                }),
            Action::make('extendToDate')
                ->label('До даты')
                ->icon('heroicon-o-calendar-days')
                ->color('gray')
                ->form([
                    DatePicker::make('ends_at')
                        ->label('Новая дата окончания')
                        ->required()
                        ->default(fn (Subscription $record) => $record->ends_at),
                    Textarea::make('note')
                        ->label('Комментарий')
                        ->rows(2),
                ])
                ->action(function (array $data, Subscription $record, SubscriptionService $service): void {
                    $note = filled($data['note'] ?? null)
                        ? now()->format('d.m.Y H:i').' — продление до '.$data['ends_at'].': '.$data['note']
                        : now()->format('d.m.Y H:i').' — продление до '.$data['ends_at'];

                    $service->extend($record, Carbon::parse($data['ends_at']), $note);

                    Notification::make()
                        ->title('Дата окончания изменена')
                        ->success()
                        ->send();

                    $this->refreshFormData(['ends_at', 'admin_note']);
                }),
            Action::make('addSessions')
                ->label('Добавить занятия')
                ->icon('heroicon-o-plus')
                ->form([
                    TextInput::make('count')
                        ->label('Сколько занятий добавить')
                        ->numeric()
                        ->minValue(1)
                        ->required()
                        ->default(1),
                ])
                ->action(function (array $data, Subscription $record, SubscriptionService $service): void {
                    $service->addSessions($record, (int) $data['count']);

                    Notification::make()
                        ->title('Занятия добавлены')
                        ->success()
                        ->send();

                    $this->refreshFormData(['sessions_total']);
                }),
            Action::make('returnSession')
                ->label('Вернуть занятие')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('warning')
                ->visible(fn (Subscription $record) => $record->sessions_used > 0)
                ->form([
                    Textarea::make('reason')
                        ->label('Причина возврата')
                        ->placeholder('Например: клиент заболел, занятие списано ошибочно')
                        ->rows(2),
                ])
                ->action(function (array $data, Subscription $record, SubscriptionService $service): void {
                    $service->returnSession($record, $data['reason'] ?? null);

                    Notification::make()
                        ->title('Занятие возвращено в абонемент')
                        ->success()
                        ->send();

                    $this->refreshFormData(['sessions_used', 'admin_note']);
                }),
            DeleteAction::make(),
        ];
    }

    private function extensionPreview(Subscription $record, int $days): string
    {
        if ($days < 1) {
            return 'Укажите количество дней.';
        }

        $baseDate = Carbon::parse($record->ends_at)->max(now()->startOfDay());
        $newEndsAt = $baseDate->copy()->addDays($days);

        return 'Сейчас до '.$record->ends_at->format('d.m.Y')
            .'. После продления — до '.$newEndsAt->format('d.m.Y').'.';
    }
}
