<?php

namespace App\Support\Push;

use App\Models\PushToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Отправка через Expo Push Service — пока приложение живёт на Expo/React Native.
 *
 * Документация: https://docs.expo.dev/push-notifications/sending-notifications/
 * Ограничение сервиса — 100 сообщений в одном запросе, поэтому режем на пачки.
 *
 * ВАЖНО про Expo Go: с SDK 53 пуши на Android в Expo Go не приходят вовсе, на
 * iOS приходят. Полноценно канал заработает только со своей сборкой
 * (APK / TestFlight). Лента уведомлений в приложении от этого не зависит.
 */
class ExpoPushSender implements PushSender
{
    private const ENDPOINT = 'https://exp.host/--/api/v2/push/send';

    private const CHUNK = 100;

    public function provider(): string
    {
        return 'expo';
    }

    public function send(array $tokens, string $title, string $body, array $data = []): int
    {
        $tokens = array_values(array_unique(array_filter($tokens, self::looksLikeExpoToken(...))));

        if ($tokens === []) {
            return 0;
        }

        $accepted = 0;

        foreach (array_chunk($tokens, self::CHUNK) as $chunk) {
            $accepted += $this->sendChunk($chunk, $title, $body, $data);
        }

        return $accepted;
    }

    /**
     * @param  list<string>  $tokens
     * @param  array<string, mixed>  $data
     */
    private function sendChunk(array $tokens, string $title, string $body, array $data): int
    {
        $messages = array_map(fn (string $token) => [
            'to' => $token,
            'title' => $title,
            'body' => $body,
            'data' => $data,
            'sound' => 'default',
            // Канал должен существовать на устройстве, иначе Android покажет
            // уведомление беззвучно в общей группе.
            'channelId' => 'default',
        ], $tokens);

        try {
            $response = Http::timeout(6)
                ->withHeaders(['Accept-Encoding' => 'gzip, deflate'])
                ->post(self::ENDPOINT, $messages);
        } catch (\Throwable $e) {
            // Сеть до exp.host отвалилась — уведомление уже лежит в ленте,
            // роняем только пуш.
            Log::warning('Пуш не отправлен: сеть', ['error' => $e->getMessage()]);

            return 0;
        }

        if ($response->failed()) {
            Log::warning('Пуш не отправлен: Expo вернул ошибку', [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 500),
            ]);

            return 0;
        }

        return $this->handleTickets($tokens, (array) $response->json('data', []));
    }

    /**
     * Expo отвечает массивом «билетов» в том же порядке, что и сообщения.
     * Билет со статусом error и кодом DeviceNotRegistered означает, что
     * приложение удалили или токен протух — такое устройство удаляем, иначе
     * оно будет висеть в базе вечно и каждый раз давать ошибку.
     *
     * @param  list<string>  $tokens
     * @param  list<array<string, mixed>>  $tickets
     */
    private function handleTickets(array $tokens, array $tickets): int
    {
        $accepted = 0;
        $dead = [];

        foreach ($tickets as $i => $ticket) {
            if (($ticket['status'] ?? null) === 'ok') {
                $accepted++;

                continue;
            }

            if (($ticket['details']['error'] ?? null) === 'DeviceNotRegistered' && isset($tokens[$i])) {
                $dead[] = $tokens[$i];
            }
        }

        if ($dead !== []) {
            PushToken::query()->whereIn('token', $dead)->delete();
        }

        return $accepted;
    }

    /**
     * Отсеиваем мусор до похода в сеть: Expo принимает только свой формат,
     * а одна кривая строка в пачке портит весь запрос.
     */
    private static function looksLikeExpoToken(string $token): bool
    {
        return (bool) preg_match('/^Expo(nent)?PushToken\[[^\]]+\]$/', $token);
    }
}
