<?php

namespace App\Filament\Resources\Subscriptions\Pages;

use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Models\Subscription;
use App\Services\SubscriptionService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
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
                    DatePicker::make('ends_at')
                        ->label('Новая дата окончания')
                        ->required()
                        ->default(fn (Subscription $record) => $record->ends_at),
                ])
                ->action(function (array $data, Subscription $record, SubscriptionService $service): void {
                    $service->extend($record, Carbon::parse($data['ends_at']));

                    Notification::make()
                        ->title('Абонемент продлён')
                        ->success()
                        ->send();

                    $this->refreshFormData(['ends_at']);
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
            DeleteAction::make(),
        ];
    }
}
