<?php

namespace App\Services;

use App\Mail\RegistrationVerificationMail;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

class RegistrationEmailVerificationService
{
    public const SESSION_EMAIL_KEY = 'registration_verify_email';

    public function ttlMinutes(): int
    {
        return (int) config('studio.registration_email_verification_ttl_minutes', 15);
    }

    public function maxAttempts(): int
    {
        return (int) config('studio.registration_email_verification_max_attempts', 5);
    }

    public function hasPending(Session $session): bool
    {
        return Cache::has($this->cacheKey($session));
    }

    public function pendingEmail(Session $session): ?string
    {
        $pending = Cache::get($this->cacheKey($session));

        return is_array($pending) ? ($pending['email'] ?? null) : null;
    }

    /**
     * @param  array<string, mixed>  $registrationData
     */
    public function start(Session $session, array $registrationData): void
    {
        $code = (string) random_int(100000, 999999);
        $email = (string) $registrationData['email'];

        Cache::put(
            $this->cacheKey($session),
            [
                'email' => $email,
                'code_hash' => hash('sha256', $code),
                'attempts' => 0,
                'data' => [
                    'first_name' => $registrationData['first_name'],
                    'last_name' => $registrationData['last_name'],
                    'patronymic' => $registrationData['patronymic'] ?? null,
                    'birth_day' => $registrationData['birth_day'],
                    'birth_month' => $registrationData['birth_month'],
                    'birth_year' => $registrationData['birth_year'] ?? null,
                    'phone' => $registrationData['phone'],
                    'email' => $email,
                    'password' => Crypt::encryptString((string) $registrationData['password']),
                ],
            ],
            now()->addMinutes($this->ttlMinutes()),
        );

        $session->put(self::SESSION_EMAIL_KEY, $email);

        try {
            Mail::to($email)->send(new RegistrationVerificationMail($code, $this->ttlMinutes()));
        } catch (\Throwable $e) {
            $this->clear($session);
            Log::error('Не удалось отправить код подтверждения регистрации', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException('Не удалось отправить код на email. Попробуйте позже или свяжитесь со студией.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function verify(Session $session, string $code): array
    {
        $cacheKey = $this->cacheKey($session);
        $pending = Cache::get($cacheKey);

        if (! is_array($pending)) {
            throw new RuntimeException('Срок действия кода истёк. Заполните форму регистрации снова.');
        }

        $attempts = (int) ($pending['attempts'] ?? 0) + 1;

        if ($attempts > $this->maxAttempts()) {
            $this->clear($session);

            throw new RuntimeException('Превышено число попыток. Заполните форму регистрации снова.');
        }

        if (! hash_equals((string) $pending['code_hash'], hash('sha256', trim($code))) {
            $pending['attempts'] = $attempts;
            Cache::put($cacheKey, $pending, now()->addMinutes($this->ttlMinutes()));

            throw new RuntimeException('Неверный код. Проверьте письмо или запросите новый код.');
        }

        $data = $pending['data'];
        $data['password'] = Crypt::decryptString((string) $data['password']);

        $this->clear($session);

        return $data;
    }

    public function clear(Session $session): void
    {
        Cache::forget($this->cacheKey($session));
        $session->forget(self::SESSION_EMAIL_KEY);
    }

    private function cacheKey(Session $session): string
    {
        return 'registration_email_pending:'.$session->getId();
    }
}
