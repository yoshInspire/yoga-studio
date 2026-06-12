<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
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

        if ($this->postMessage($token, $chatId, $text, 'HTML')) {
            return true;
        }

        return $this->postMessage($token, $chatId, strip_tags($text));
    }

    private function postMessage(string $token, int $chatId, string $text, ?string $parseMode = null): bool
    {
        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'disable_web_page_preview' => true,
        ];

        if ($parseMode !== null) {
            $payload['parse_mode'] = $parseMode;
        }

        try {
            $response = $this->httpClient()
                ->post($this->apiUrl($token, 'sendMessage'), $payload);

            if ($response->successful() && $response->json('ok') === true) {
                return true;
            }

            Log::warning('Telegram-уведомление не доставлено', [
                'chat_id' => $chatId,
                'status' => $response->status(),
                'description' => $response->json('description'),
                'error_code' => $response->json('error_code'),
            ]);
        } catch (\Throwable $e) {
            Log::error('Ошибка отправки Telegram-уведомления', [
                'chat_id' => $chatId,
                'error' => $this->sanitizeErrorMessage($e->getMessage()),
            ]);
        }

        return false;
    }

    private function httpClient(): PendingRequest
    {
        $options = [
            'curl' => [
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            ],
        ];

        $resolve = config('services.telegram.curl_resolve');

        if (filled($resolve)) {
            $options['curl'][CURLOPT_RESOLVE] = [(string) $resolve];
        }

        return Http::timeout(10)
            ->asForm()
            ->withOptions($options);
    }

    private function apiUrl(string $token, string $method): string
    {
        return 'https://api.telegram.org/bot'.$token.'/'.$method;
    }

    private function sanitizeErrorMessage(string $message): string
    {
        return preg_replace('/bot\d+:[A-Za-z0-9_-]+/', 'bot***', $message) ?? $message;
    }
}
