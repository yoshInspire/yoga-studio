<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ClientAccessService
{
    public function __construct(
        private NotificationService $notifications,
    ) {}

    /**
     * Сгенерировать временный пароль, сохранить и отправить клиенту.
     *
     * @return array{password: string, email: bool, telegram: bool}
     */
    public function sendTemporaryPassword(User $user): array
    {
        if ($user->role !== UserRole::Client) {
            throw new InvalidArgumentException('Отправка доступа доступна только для клиентов.');
        }

        if (blank($user->email) && $user->telegram_id === null) {
            throw new InvalidArgumentException('У клиента нет email, а Telegram не привязан. Укажите email в карточке или дождитесь, пока клиент привяжет Telegram в личном кабинете.');
        }

        $password = Str::password(10, letters: true, numbers: true, symbols: false);

        $user->update(['password' => $password]);

        $phone = $user->formattedPhone() ?? $user->phone ?? '—';

        $delivery = $this->notifications->notifyUser(
            $user,
            'Доступ в личный кабинет',
            [
                'Мы создали для вас доступ к личному кабинету студии.',
                'Сайт: https://ekoyoga-ik.ru/login',
                'Телефон для входа: '.$phone,
                'Временный пароль: '.$password,
                'В личном кабинете видны абонементы и записи. Записаться на занятия можно в разделе «Расписание».',
                'Если вы не запрашивали доступ — напишите в студию.',
            ],
            'Доступ в личный кабинет',
        );

        return [
            'password' => $password,
            'email' => $delivery['email'],
            'telegram' => $delivery['telegram'],
        ];
    }
}
