<?php

namespace App\Services;

use App\Mail\StudioNotificationMail;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Маршрутизация уведомлений студии по каналам.
 *
 * Правило каналов клиента:
 *  - привязан Telegram → шлём в Telegram;
 *  - указана почта → шлём на почту;
 *  - есть и то и другое → шлём в оба канала.
 * Администратору письма уходят на почту рассылки (studio.admin_email).
 */
class NotificationService
{
    public function __construct(
        private TelegramNotifier $telegram,
    ) {}

    /**
     * Уведомить клиента по доступным каналам.
     *
     * @param  list<string>  $lines
     * @return array{email: bool, telegram: bool}
     */
    public function notifyUser(User $user, string $heading, array $lines, ?string $subject = null): array
    {
        $result = ['email' => false, 'telegram' => false];

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
