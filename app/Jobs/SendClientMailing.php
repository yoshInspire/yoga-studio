<?php

namespace App\Jobs;

use App\Models\ClientMailingLog;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Одно сообщение одному клиенту — единица массовой рассылки.
 *
 * Почему по одному получателю на задание, а не одно задание на всю рассылку:
 * задание должно быть коротким. Внутри `NotificationService::notifyUser()`
 * живут SMTP, Telegram и пуш — на семи десятках клиентов это минуты, и всё,
 * что длится минуты, обязано переживать перезапуск воркера, а не начинаться
 * заново.
 *
 * **Защита от дубля — сама запись в журнал.** Строка `client_mailing_logs`
 * уникальна по (user_id, type, mailing_key), поэтому право на отправку
 * «занимается» вставкой ДО обращения к почте: не вставилось — значит это
 * сообщение человеку уже уходило, и второй копии не будет. Именно так
 * повторное нажатие «Отправить» перестаёт быть повторной рассылкой.
 *
 * Порядок «сначала запись, потом отправка» выбран сознательно: у него цена
 * сбоя — не доставленное сообщение (видно в логе, можно послать заново), а у
 * обратного порядка — второе письмо тому же человеку, чего мы и добиваемся
 * не допустить.
 */
class SendClientMailing implements ShouldQueue
{
    use Queueable;

    /**
     * Повторов нет: задание уже «заняло» строку журнала, и вторая попытка
     * либо ничего не сделает, либо (если снять защиту) пошлёт копию.
     */
    public int $tries = 1;

    public int $timeout = 120;

    /**
     * @param  list<string>  $lines
     * @param  string  $notificationType  вид уведомления в ленте приложения
     * @param  string  $logType  тип рассылки в журнале `client_mailing_logs`
     * @param  array<string, mixed>  $payload  куда вести по тапу в приложении
     */
    public function __construct(
        public int $userId,
        public string $heading,
        public array $lines,
        public ?string $subject,
        public string $notificationType,
        public string $logType,
        public string $mailingKey,
        public array $payload = [],
    ) {}

    public function handle(NotificationService $notifications): void
    {
        $user = User::query()->find($this->userId);

        // Клиента удалили или обезличили, пока задание ждало очереди.
        if ($user === null || $user->anonymized_at !== null) {
            return;
        }

        if (! $this->claim()) {
            return;
        }

        $notifications->notifyUser(
            $user,
            $this->heading,
            $this->lines,
            $this->subject,
            type: $this->notificationType,
            payload: $this->payload,
        );
    }

    public function failed(\Throwable $e): void
    {
        Log::error('Рассылка не доставлена', [
            'user_id' => $this->userId,
            'type' => $this->logType,
            'mailing_key' => $this->mailingKey,
            'error' => $e->getMessage(),
        ]);
    }

    /**
     * Занять право на отправку. Вернёт false, если этому человеку это же
     * сообщение уже уходило.
     */
    private function claim(): bool
    {
        $now = now();

        return ClientMailingLog::query()->insertOrIgnore([
            'user_id' => $this->userId,
            'type' => $this->logType,
            'mailing_key' => $this->mailingKey,
            'sent_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]) === 1;
    }
}
