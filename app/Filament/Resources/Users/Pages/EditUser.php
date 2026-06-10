<?php

namespace App\Filament\Resources\Users\Pages;

use App\Enums\UserRole;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Services\ClientAccessService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use InvalidArgumentException;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sendAccess')
                ->label('Отправить доступ')
                ->icon('heroicon-o-paper-airplane')
                ->color('success')
                ->visible(fn (User $record): bool => $record->role === UserRole::Client)
                ->requiresConfirmation()
                ->modalHeading('Отправить временный пароль')
                ->modalDescription(function (User $record): string {
                    $channels = array_filter([
                        filled($record->email) ? 'email: '.$record->email : null,
                        $record->hasTelegram() ? 'Telegram: '.$record->telegramDisplayAccount() : null,
                    ]);

                    if ($channels === []) {
                        return 'Укажите email в карточке клиента и сохраните запись. Telegram администратор не привязывает — клиент может сделать это сам в личном кабинете после входа.';
                    }

                    $telegramNote = $record->hasTelegram()
                        ? ''
                        : ' Telegram пока не привязан — после входа клиент может привязать его в личном кабинете.';

                    return 'Система сгенерирует новый временный пароль, сохранит его и отправит: '.implode('; ', $channels).'.'.$telegramNote;
                })
                ->disabled(fn (User $record): bool => blank($record->email) && ! $record->hasTelegram())
                ->action(function (User $record, ClientAccessService $access): void {
                    try {
                        $result = $access->sendTemporaryPassword($record);
                    } catch (InvalidArgumentException $e) {
                        Notification::make()
                            ->title('Не удалось отправить доступ')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    $record->refresh();

                    $this->form->fill([
                        ...$this->form->getState(),
                        'password' => $result['password'],
                    ]);

                    $channels = array_filter([
                        $result['email'] ? 'email' : null,
                        $result['telegram'] ? 'Telegram' : null,
                    ]);

                    if ($channels === []) {
                        Notification::make()
                            ->title('Пароль обновлён, но доставка не удалась')
                            ->body('Новый пароль: '.$result['password'].'. Проверьте настройки почты и Telegram на сервере.')
                            ->warning()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Временный пароль отправлен')
                        ->body('Доставлено: '.implode(' и ', $channels).'. Пароль: '.$result['password'])
                        ->success()
                        ->send();
                }),
            DeleteAction::make(),
        ];
    }
}
