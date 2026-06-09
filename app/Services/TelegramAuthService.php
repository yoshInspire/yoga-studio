<?php

namespace App\Services;

use App\Models\User;
use App\Support\TelegramAuthData;
use Illuminate\Support\Arr;

class TelegramAuthService
{
    public function isEnabled(): bool
    {
        return filled(config('services.telegram.bot_token'))
            && filled(config('services.telegram.bot_username'));
    }

    public function botUsername(): ?string
    {
        $username = config('services.telegram.bot_username');

        return filled($username) ? ltrim((string) $username, '@') : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function parseAndVerify(array $payload): ?TelegramAuthData
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $hash = (string) Arr::get($payload, 'hash', '');

        if ($hash === '' || ! $this->verifyHash($payload, $hash)) {
            return null;
        }

        $authDate = (int) Arr::get($payload, 'auth_date', 0);
        $maxAge = (int) config('services.telegram.auth_max_age', 86400);

        if ($authDate <= 0 || (time() - $authDate) > $maxAge) {
            return null;
        }

        $id = (int) Arr::get($payload, 'id', 0);

        if ($id <= 0) {
            return null;
        }

        $firstName = trim((string) Arr::get($payload, 'first_name', ''));

        if ($firstName === '') {
            return null;
        }

        return new TelegramAuthData(
            id: $id,
            first_name: $firstName,
            last_name: $this->nullableString($payload, 'last_name'),
            username: $this->nullableString($payload, 'username'),
            photo_url: $this->nullableString($payload, 'photo_url'),
            auth_date: $authDate,
        );
    }

    public function findUserByTelegramId(int $telegramId): ?User
    {
        return User::query()->where('telegram_id', $telegramId)->first();
    }

    public function linkUser(User $user, TelegramAuthData $data): void
    {
        $user->forceFill([
            'telegram_id' => $data->id,
            'telegram_username' => $data->username,
            'telegram_linked_at' => now(),
        ])->save();
    }

    public function unlinkUser(User $user): void
    {
        $user->forceFill([
            'telegram_id' => null,
            'telegram_username' => null,
            'telegram_linked_at' => null,
        ])->save();
    }

    public function isLinkedToAnotherUser(TelegramAuthData $data, ?User $except = null): bool
    {
        $query = User::query()->where('telegram_id', $data->id);

        if ($except !== null) {
            $query->whereKeyNot($except->getKey());
        }

        return $query->exists();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function verifyHash(array $payload, string $hash): bool
    {
        $checkPairs = [];

        foreach ($payload as $key => $value) {
            if ($key === 'hash' || $value === null || $value === '') {
                continue;
            }

            $checkPairs[] = $key.'='.$value;
        }

        sort($checkPairs);

        $dataCheckString = implode("\n", $checkPairs);
        $secretKey = hash('sha256', (string) config('services.telegram.bot_token'), true);
        $calculatedHash = hash_hmac('sha256', $dataCheckString, $secretKey);

        return hash_equals($calculatedHash, $hash);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function nullableString(array $payload, string $key): ?string
    {
        $value = Arr::get($payload, $key);

        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
