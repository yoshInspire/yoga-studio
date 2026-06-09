<?php

namespace App\Services;

use App\Mail\RegistrationVerificationMail;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

class ProfileEmailVerificationService
{
    public function ttlMinutes(): int
    {
        return (int) config('studio.registration_email_verification_ttl_minutes', 15);
    }

    public function maxAttempts(): int
    {
        return (int) config('studio.registration_email_verification_max_attempts', 5);
    }

    public function pendingEmail(User $user): ?string
    {
        $pending = Cache::get($this->pendingKey($user));

        return is_array($pending) ? ($pending['email'] ?? null) : null;
    }

    public function verifiedEmail(User $user): ?string
    {
        $verified = Cache::get($this->verifiedKey($user));

        return is_string($verified) ? $verified : null;
    }

    public function sendCode(User $user, string $email): void
    {
        $email = mb_strtolower(trim($email));

        $code = (string) random_int(100000, 999999);

        Cache::put(
            $this->pendingKey($user),
            [
                'email' => $email,
                'code_hash' => hash('sha256', $code),
                'attempts' => 0,
            ],
            now()->addMinutes($this->ttlMinutes()),
        );

        Cache::forget($this->verifiedKey($user));

        try {
            Mail::to($email)->send(new RegistrationVerificationMail($code, $this->ttlMinutes(), 'profile'));
        } catch (\Throwable $e) {
            Cache::forget($this->pendingKey($user));
            Log::error('Не удалось отправить код подтверждения email в профиле', [
                'user_id' => $user->id,
                'email' => $email,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException('Не удалось отправить код на email. Попробуйте позже или свяжитесь со студией.');
        }
    }

    public function verifyCode(User $user, string $code): string
    {
        $pending = Cache::get($this->pendingKey($user));

        if (! is_array($pending)) {
            throw new RuntimeException('Срок действия кода истёк. Запросите новый код.');
        }

        $attempts = (int) ($pending['attempts'] ?? 0) + 1;

        if ($attempts > $this->maxAttempts()) {
            $this->clear($user);

            throw new RuntimeException('Превышено число попыток. Запросите новый код.');
        }

        if (! hash_equals((string) $pending['code_hash'], hash('sha256', trim($code)))) {
            $pending['attempts'] = $attempts;
            Cache::put($this->pendingKey($user), $pending, now()->addMinutes($this->ttlMinutes()));

            throw new RuntimeException('Неверный код. Проверьте письмо или запросите новый код.');
        }

        $email = (string) $pending['email'];
        Cache::put($this->verifiedKey($user), $email, now()->addMinutes($this->ttlMinutes()));
        Cache::forget($this->pendingKey($user));

        return $email;
    }

    public function isVerified(User $user, string $email): bool
    {
        $verified = Cache::get($this->verifiedKey($user));

        return $verified === mb_strtolower(trim($email));
    }

    public function clear(User $user): void
    {
        Cache::forget($this->pendingKey($user));
        Cache::forget($this->verifiedKey($user));
    }

    private function pendingKey(User $user): string
    {
        return 'profile_email_pending:'.$user->id;
    }

    private function verifiedKey(User $user): string
    {
        return 'profile_email_verified:'.$user->id;
    }
}
