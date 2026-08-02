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
        if (! in_array($user->role, [UserRole::Client, UserRole::Trainer], true)) {
            throw new InvalidArgumentException('Отправка доступа доступна только для клиентов и тренеров.');
        }

        if (blank($user->email) && $user->telegram_id === null) {
            throw new InvalidArgumentException('Нет email, а Telegram не привязан. Укажите email в карточке или дождитесь, пока пользователь привяжет Telegram после входа на сайт.');
        }

        $password = Str::password(10, letters: true, numbers: true, symbols: false);

        $user->update(['password' => $password]);

        $phone = $user->formattedPhone() ?? $user->phone ?? '—';
        [$heading, $lines, $subject] = $this->accessMessage($user, $password, $phone);

        $delivery = $this->notifications->notifyUser($user, $heading, $lines, $subject);

        return [
            'password' => $password,
            'email' => $delivery['email'],
            'telegram' => $delivery['telegram'],
        ];
    }

    /**
     * @return array{0: string, 1: list<string>, 2: string}
     */
    private function accessMessage(User $user, string $password, string $phone): array
    {
        $common = [
            'Сайт: https://ekoyoga-ik.ru/login',
            'Телефон для входа: '.$phone,
            'Временный пароль: '.$password,
            'Если вы не запрашивали доступ — напишите в студию.',
        ];

        return match ($user->role) {
            UserRole::Trainer => [
                'Доступ для тренера',
                [
                    'Мы создали для вас доступ к панели тренера на сайте студии.',
                    ...$common,
                    'После входа откроется раздел для тренеров.',
                ],
                'Доступ для тренера',
            ],
            default => [
                'Доступ в личный кабинет',
                [
                    'Мы создали для вас доступ к личному кабинету студии.',
                    ...$common,
                    'В личном кабинете видны абонементы и бронирования. Забронировать место можно в разделе «Расписание».',
                ],
                'Доступ в личный кабинет',
            ],
        };
    }
}
