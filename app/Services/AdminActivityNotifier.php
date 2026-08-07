<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Message;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use App\Support\RussianDate;

/**
 * Служебные письма администратору о действиях клиентов на сайте (личный кабинет).
 */
class AdminActivityNotifier
{
    public function __construct(
        private NotificationService $notifications,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('studio.admin_activity_notifications.enabled', true);
    }

    /**
     * @param  list<string>  $lines
     */
    public function notify(string $heading, array $lines, ?string $subject = null): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $this->notifications->notifyAdmin($heading, $lines, $subject ?? $heading);
    }

    public function clientRegistered(User $user, bool $viaTelegram = false): void
    {
        $lines = [
            ...$this->clientLines($user),
            'Действие: регистрация нового клиента',
            'Способ: '.($viaTelegram ? 'через Telegram' : 'на сайте с подтверждением email'),
        ];

        if ($user->telegram_username) {
            $lines[] = 'Telegram: @'.$user->telegram_username;
        }

        $this->notify('Новый клиент', $lines, 'Новый клиент на сайте');
    }

    public function clientBooked(User $user, Booking $booking): void
    {
        $session = $booking->classSession;

        $this->notify(
            'Запись на занятие',
            [
                ...$this->clientLines($user),
                'Действие: запись на занятие',
                ...$this->sessionLines($session),
            ],
            'Клиент записался на занятие',
        );
    }

    public function clientCancelledBooking(User $user, Booking $booking): void
    {
        $session = $booking->classSession;

        $this->notify(
            'Отмена записи',
            [
                ...$this->clientLines($user),
                'Действие: отмена записи клиентом',
                ...$this->sessionLines($session),
            ],
            'Клиент отменил запись',
        );
    }

    public function clientRescheduledBooking(User $user, ClassSession $fromSession, ClassSession $toSession): void
    {
        $this->notify(
            'Перенос записи',
            [
                ...$this->clientLines($user),
                'Действие: перенос записи',
                'Было: «'.$fromSession->title.'» · '.$fromSession->formattedDateTime(),
                'Стало: «'.$toSession->title.'» · '.$toSession->formattedDateTime(),
            ],
            'Клиент перенёс запись',
        );
    }

    /**
     * @param  list<string>  $changes
     */
    public function clientUpdatedProfile(User $user, array $changes): void
    {
        if ($changes === []) {
            return;
        }

        $this->notify(
            'Обновление профиля',
            [
                ...$this->clientLines($user),
                'Действие: изменение данных профиля',
                ...$changes,
            ],
            'Клиент обновил профиль',
        );
    }

    public function clientChangedPassword(User $user): void
    {
        $this->notify(
            'Смена пароля',
            [
                ...$this->clientLines($user),
                'Действие: клиент сменил пароль в личном кабинете',
            ],
            'Клиент сменил пароль',
        );
    }

    public function clientAcceptedOffer(User $user): void
    {
        $this->notify(
            'Согласие с офертой',
            [
                ...$this->clientLines($user),
                'Действие: принято согласие с договором-офертой',
            ],
            'Клиент принял оферту',
        );
    }

    /**
     * Письмо собирается ДО обезличивания: после него имени и телефона в базе
     * уже не будет, а студии нужно понимать, кто ушёл и кому освободились места.
     */
    public function clientDeletedAccount(User $user, int $cancelledBookings): void
    {
        $this->notify(
            'Удаление аккаунта',
            [
                ...$this->clientLines($user),
                'Действие: клиент удалил аккаунт, данные профиля обезличены',
                $cancelledBookings > 0
                    ? sprintf('Отменено будущих записей: %d', $cancelledBookings)
                    : 'Будущих записей не было',
            ],
            'Клиент удалил аккаунт',
        );
    }

    public function clientStartedPurchase(User $user, Payment $payment): void
    {
        $this->notify(
            'Начало оплаты абонемента',
            [
                ...$this->clientLines($user),
                'Действие: клиент перешёл к оплате абонемента',
                'Товар: '.$payment->description,
                'Сумма: '.$payment->formattedAmount(),
                'Дата начала абонемента: '.RussianDate::dayMonthYear($payment->starts_at),
            ],
            'Клиент начал оплату абонемента',
        );
    }

    public function clientPaidSubscription(User $user, Payment $payment, Subscription $subscription): void
    {
        $this->notify(
            'Оплата абонемента',
            [
                ...$this->clientLines($user),
                'Действие: успешная оплата абонемента',
                'Товар: '.$payment->description,
                'Сумма: '.$payment->formattedAmount(),
                'Абонемент: '.$subscription->type->label().', '.$subscription->sessions_total.' зан.',
                'Действует с: '.RussianDate::dayMonthYear($subscription->starts_at),
                'Действует до: '.RussianDate::dayMonthYear($subscription->ends_at),
            ],
            'Клиент оплатил абонемент',
        );
    }

    public function clientWroteMessage(User $user, Message $message): void
    {
        $lines = [
            ...$this->clientLines($user),
            'Действие: сообщение в чате приложения',
        ];

        $text = $message->preview(300);

        if ($text !== '') {
            $lines[] = 'Сообщение: «'.$text.'»';
        }

        $this->notify('Сообщение в чате', $lines, 'Клиент написал в чат');
    }

    public function clientLinkedTelegram(User $user): void
    {
        $lines = [
            ...$this->clientLines($user),
            'Действие: привязка Telegram к аккаунту',
        ];

        if ($user->telegram_username) {
            $lines[] = 'Telegram: @'.$user->telegram_username;
        }

        $this->notify('Привязка Telegram', $lines, 'Клиент привязал Telegram');
    }

    public function clientUnlinkedTelegram(User $user): void
    {
        $this->notify(
            'Отвязка Telegram',
            [
                ...$this->clientLines($user),
                'Действие: клиент отвязал Telegram от аккаунта',
            ],
            'Клиент отвязал Telegram',
        );
    }

    /**
     * @return list<string>
     */
    private function clientLines(User $user): array
    {
        return [
            'Клиент: '.$user->fullName(),
            'Телефон: '.($user->formattedPhone() ?? $user->phone ?? '—'),
            'Email: '.($user->email ?? '—'),
        ];
    }

    /**
     * @return list<string>
     */
    private function sessionLines(ClassSession $session): array
    {
        return [
            'Занятие: «'.$session->title.'»',
            'Дата и время: '.$session->formattedDateTime(),
        ];
    }
}
