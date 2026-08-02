<?php

namespace App\Services;

use App\Models\ClientMailingLog;
use App\Models\StudioText;
use App\Models\User;

/**
 * Памятка «К вашему визиту»: уходит клиенту один раз — когда он впервые
 * забронировал место. Текст правится администратором в разделе «Рассылки».
 */
class WelcomeMessageService
{
    public const HEADING = 'К вашему визиту';

    public function __construct(
        private NotificationService $notifications,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) (config('studio.mailings.welcome_visit.enabled') ?? true);
    }

    /** Текущий текст: правка администратора либо значение по умолчанию. */
    public function body(): string
    {
        return StudioText::body(StudioText::WELCOME_VISIT, $this->defaultBody());
    }

    public function saveBody(string $body): void
    {
        StudioText::put(StudioText::WELCOME_VISIT, $body);
    }

    public function alreadySent(User $user): bool
    {
        return ClientMailingLog::query()
            ->where('user_id', $user->id)
            ->where('type', ClientMailingLog::TYPE_WELCOME)
            ->exists();
    }

    /**
     * Отправить памятку, если это первое бронирование клиента.
     *
     * Вызывается после подтверждённого бронирования. Повторно не уходит:
     * признак хранится в журнале рассылок.
     */
    public function sendOnFirstBooking(User $user): bool
    {
        if (! $this->isEnabled() || ! $user->isClient()) {
            return false;
        }

        // Писать некуда — ни почты, ни Telegram.
        if (blank($user->email) && $user->telegram_id === null) {
            return false;
        }

        if ($this->alreadySent($user)) {
            return false;
        }

        $body = trim($this->body());

        if ($body === '') {
            return false;
        }

        $this->notifications->notifyUser(
            $user,
            self::HEADING,
            $this->lines($body),
            self::HEADING,
        );

        ClientMailingLog::query()->create([
            'user_id' => $user->id,
            'type' => ClientMailingLog::TYPE_WELCOME,
            'mailing_key' => now()->toDateString(),
            'sent_at' => now(),
        ]);

        return true;
    }

    /**
     * @return list<string>
     */
    public function lines(?string $body = null): array
    {
        $body = $body ?? trim($this->body());

        return array_values(array_filter(
            array_map('trim', preg_split('/\R/u', $body) ?: []),
            fn (string $line): bool => $line !== '',
        ));
    }

    /** Текст, согласованный со студией. Администратор может его изменить. */
    public function defaultBody(): string
    {
        return <<<'TEXT'
        Вы забронировали место на занятие в студии ЭКО YOGA. Будем рады вас видеть!

        ✅ Одежда: удобная, не сковывающая движений — лосины, леггинсы или шорты и футболка либо топ.
        ✅ Носки: по желанию, но мы рекомендуем заниматься босиком, так лучше сцепление. Если предпочитаете в носках, идеально подойдут антискользящие — они есть у нас в продаже.
        ✅ Сменная обувь: возьмите шлёпки, чтобы комфортно дойти из раздевалки в зал.

        Просьба: для вашей же безопасности сообщите тренеру до начала занятия или сообщением о любых нюансах здоровья — травмы спины, проблемы с суставами, давление, грыжи и протрузии, недавние операции или беременность. Это поможет тренеру адаптировать практику именно под вас.

        Если возникнут вопросы — смело пишите, я на связи.
        🙏🧘
        TEXT;
    }
}
