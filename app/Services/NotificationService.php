<?php

namespace App\Services;

use App\Mail\StudioNotificationMail;
use App\Models\ClientNotification;
use App\Models\User;
use App\Support\Push\PushSender;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Маршрутизация уведомлений студии по каналам.
 *
 * Через notifyUser() проходят все уведомления клиенту — напоминание накануне,
 * анонс недели, отмена занятия, кончающийся абонемент, новость, поздравление
 * и т.д. Это единственная точка, поэтому лента в приложении и пуши подключены
 * именно здесь: любой новый вид уведомления попадёт в них автоматически.
 *
 * Каналы клиента:
 *  - лента в приложении — всегда (она же история, её нельзя пропустить);
 *  - пуш — на все зарегистрированные устройства;
 *  - привязан Telegram → шлём в Telegram;
 *  - указана почта → шлём на почту.
 * Администратору письма уходят на почту рассылки (studio.admin_email).
 */
class NotificationService
{
    public function __construct(
        private TelegramNotifier $telegram,
        private PushSender $push,
    ) {}

    /**
     * Уведомить клиента по доступным каналам.
     *
     * @param  list<string>  $lines
     * @param  string  $type  вид уведомления: задаёт иконку и переход в приложении
     * @param  array<string, mixed>  $payload  куда вести по тапу: {"session_id": 12}
     * @return array{email: bool, telegram: bool, push: int, stored: bool}
     */
    public function notifyUser(
        User $user,
        string $heading,
        array $lines,
        ?string $subject = null,
        string $type = 'studio',
        array $payload = [],
    ): array {
        $result = ['email' => false, 'telegram' => false, 'push' => 0, 'stored' => false];

        // Сначала лента: она должна получить уведомление, даже если все внешние
        // каналы отвалятся. Провал записи не должен ронять отправку остального.
        try {
            ClientNotification::query()->create([
                'user_id' => $user->id,
                'type' => $type,
                'title' => $heading,
                'body' => $this->formatBody($lines),
                'payload' => $payload === [] ? null : $payload,
            ]);
            $result['stored'] = true;
        } catch (\Throwable $e) {
            Log::error('Не удалось сохранить уведомление в ленту', [
                'user_id' => $user->id,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
        }

        $result['push'] = $this->sendPush($user, $heading, $lines, $type, $payload);

        if ($user->telegram_id !== null) {
            $result['telegram'] = $this->telegram->send(
                (int) $user->telegram_id,
                $this->formatTelegram($heading, $lines),
            );
        }

        if (filled($user->email)) {
            $result['email'] = $this->sendMail($user->email, $heading, $lines, $subject);
        }

        return $result;
    }

    /**
     * @param  list<string>  $lines
     * @param  array<string, mixed>  $payload
     */
    private function sendPush(User $user, string $heading, array $lines, string $type, array $payload): int
    {
        $tokens = $user->pushTokens()
            ->where('provider', $this->push->provider())
            ->pluck('token')
            ->all();

        // Ни одного устройства — не ходим в сеть вовсе. Это обычный случай:
        // у клиентов, пользующихся только сайтом, устройств не будет никогда.
        if ($tokens === []) {
            return 0;
        }

        return $this->push->send(
            $tokens,
            $heading,
            $this->pushBody($lines),
            $payload + ['type' => $type],
        );
    }

    /**
     * В шторке уведомления помещается пара строк, а первая строка у нас почти
     * всегда «Здравствуйте, Имя!» — в пуше она бесполезна, отбрасываем.
     *
     * @param  list<string>  $lines
     */
    private function pushBody(array $lines): string
    {
        $meaningful = array_values(array_filter(
            $lines,
            fn (string $line) => ! preg_match('/^Здравствуйте[,!]/u', trim($line)),
        ));

        return implode(' ', $meaningful === [] ? $lines : $meaningful);
    }

    /**
     * @param  list<string>  $lines
     */
    private function formatBody(array $lines): string
    {
        return implode("\n", array_map(trim(...), $lines));
    }

    /**
     * Отправить клиенту сообщение только в Telegram.
     *
     * @param  list<string>  $lines
     */
    public function notifyUserTelegram(User $user, string $heading, array $lines): bool
    {
        if ($user->telegram_id === null) {
            return false;
        }

        return $this->telegram->send(
            (int) $user->telegram_id,
            $this->formatTelegram($heading, $lines),
        );
    }

    /**
     * Уведомить администратора (почта рассылки).
     *
     * @param  list<string>  $lines
     */
    public function notifyAdmin(string $heading, array $lines, ?string $subject = null): bool
    {
        $email = config('studio.admin_email');

        if (blank($email)) {
            return false;
        }

        return $this->sendMail((string) $email, $heading, $lines, $subject, footnote: 'Служебное уведомление администратору студии.');
    }

    /**
     * @param  list<string>  $lines
     */
    private function sendMail(string $to, string $heading, array $lines, ?string $subject, ?string $footnote = null): bool
    {
        try {
            Mail::to($to)->send(new StudioNotificationMail($heading, $lines, $subject, $footnote));

            return true;
        } catch (\Throwable $e) {
            Log::error('Не удалось отправить email-уведомление', [
                'to' => $to,
                'heading' => $heading,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @param  list<string>  $lines
     */
    private function formatTelegram(string $heading, array $lines): string
    {
        $body = implode("\n", array_map(fn (string $line) => e($line), $lines));

        return '<b>'.e($heading)."</b>\n\n".$body;
    }
}
