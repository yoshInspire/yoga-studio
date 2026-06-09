<?php

namespace Tests\Support;

class TelegramAuthTestHelper
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function signedPayload(array $overrides = [], string $botToken = 'test-bot-token'): array
    {
        $payload = array_merge([
            'id' => 123456789,
            'first_name' => 'Иван',
            'last_name' => 'Петров',
            'username' => 'ivan_petrov',
            'auth_date' => time(),
        ], $overrides);

        $checkPairs = [];

        foreach ($payload as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $checkPairs[] = $key.'='.$value;
        }

        sort($checkPairs);

        $dataCheckString = implode("\n", $checkPairs);
        $secretKey = hash('sha256', $botToken, true);
        $payload['hash'] = hash_hmac('sha256', $dataCheckString, $secretKey);

        return $payload;
    }
}
