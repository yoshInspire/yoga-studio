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
                ->visible(fn (User $record): bool => in_array($record->role, [UserRole::Client, UserRole::Trainer], true))
                ->requiresConfirmation()
                // Кнопку не отключаем: бледная неактивная кнопка без пояснений выглядит
                // как поломка. Если отправлять некуда — модалка объясняет, что сделать.
                ->modalHeading(fn (User $record): string => $this->accessBlocker($record) === null
                    ? 'Отправить временный пароль'
                    : 'Некуда отправить доступ')
                ->modalDescription(function (User $record): string {
                    if (($blocker = $this->accessBlocker($record)) !== null) {
                        return $blocker;
                    }

                    $channels = array_filter([
                        filled($record->email) ? 'email: '.$record->email : null,
                        $record->hasTelegram() ? 'Telegram: '.$record->telegramDisplayAccount() : null,
                    ]);

                    $telegramNote = $record->hasTelegram()
                        ? ''
                        : ' Telegram пока не привязан — после входа пользователь может привязать его на сайте.';

                    return 'Система сгенерирует новый временный пароль, сохранит его и отправит: '.implode('; ', $channels).'.'.$telegramNote;
                })
                ->modalSubmitAction(fn (User $record) => $this->accessBlocker($record) === null ? null : false)
                ->modalCancelActionLabel(fn (User $record): string => $this->accessBlocker($record) === null ? 'Отмена' : 'Понятно')
                ->action(function (User $record, ClientAccessService $access): void {
                    if (($blocker = $this->accessBlocker($record)) !== null) {
                        Notification::make()
                            ->title('Не удалось отправить доступ')
                            ->body($blocker)
                            ->danger()
                            ->send();

                        return;
                    }

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

    /**
     * Почему доступ сейчас нельзя отправить (null — можно).
     *
     * Отправка идёт по сохранённой карточке, поэтому отдельно ловим случай,
     * когда email уже вписан в форму, но «Сохранить» ещё не нажали.
     */
    private function accessBlocker(User $record): ?string
    {
        $formEmail = trim((string) ($this->data['email'] ?? ''));

        if ($formEmail !== '' && $formEmail !== (string) $record->email) {
            return 'Email в карточке изменён, но не сохранён. Нажмите «Сохранить» внизу страницы, затем снова «Отправить доступ».';
        }

        if (blank($record->email) && ! $record->hasTelegram()) {
            return 'В карточке не указан email, а Telegram не привязан. Впишите email, нажмите «Сохранить» внизу страницы, затем снова «Отправить доступ».';
        }

        return null;
    }
}
