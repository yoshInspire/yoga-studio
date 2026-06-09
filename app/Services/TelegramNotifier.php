<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Отправка сообщений пользователям через Telegram Bot API.
 * Работает только если бот может писать пользователю (пользователь хотя бы раз
 * запускал бота). Токен бота — services.telegram.bot_token.
 */
class TelegramNotifier
{
    public function isEnabled(): bool
    {
        return filled(config('services.telegram.bot_token'));
    }

    /**
     * Отправить текстовое сообщение в чат Telegram.
     * Возвращает true при успешной доставке.
     */
    public function send(int $chatId, string $text): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        $token = (string) config('services.telegram.bot_token');

        try {
            $response = Http::timeout(10)
                ->asForm()
                ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => $text,
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true,
                ]);

            if ($response->successful() && $response->json('ok') === true) {
                return true;
            }

            Log::warning('Telegram-уведомление не доставлено', [
                'chat_id' => $chatId,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Ошибка отправки Telegram-уведомления', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);
        }

        return false;
    }
}
