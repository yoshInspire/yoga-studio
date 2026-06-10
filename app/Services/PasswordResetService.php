<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Mail\RegistrationVerificationMail;
use App\Models\User;
use App\Support\PhoneNormalizer;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

class PasswordResetService
{
    public const SESSION_KEY = 'password_reset_hint';

    public function __construct(
        private TelegramNotifier $telegram,
    ) {}

    public function ttlMinutes(): int
    {
        return (int) config('studio.password_reset_ttl_minutes', 15);
    }

    public function maxAttempts(): int
    {
        return (int) config('studio.password_reset_max_attempts', 5);
    }

    public function hasPending(Session $session): bool
    {
        return Cache::has($this->cacheKey($session));
    }

    public function deliveryHint(Session $session): ?string
    {
        $pending = Cache::get($this->cacheKey($session));

        return is_array($pending) ? ($pending['hint'] ?? null) : null;
    }

    public function start(Session $session, string $phone): User
    {
        $normalized = PhoneNormalizer::normalize($phone);

        if ($normalized === null) {
            throw new RuntimeException('Введите корректный номер телефона.');
        }

        $user = User::query()
            ->where('phone', $normalized)
            ->where('role', UserRole::Client->value)
            ->first();

        if ($user === null) {
            throw new RuntimeException('Клиент с таким телефоном не найден. Обратитесь в студию.');
        }

        if (blank($user->email) && $user->telegram_id === null) {
            throw new RuntimeException('Для восстановления пароля в профиле должен быть email или привязанный Telegram. Обратитесь в студию — администратор укажет email, Telegram клиент привязывает сам в личном кабинете.');
        }

        $code = (string) random_int(100000, 999999);

        Cache::put(
            $this->cacheKey($session),
            [
                'user_id' => $user->id,
                'code_hash' => hash('sha256', $code),
                'attempts' => 0,
                'hint' => $this->buildDeliveryHint($user),
            ],
            now()->addMinutes($this->ttlMinutes()),
        );

        $session->put(self::SESSION_KEY, $this->buildDeliveryHint($user));

        $this->deliverCode($user, $code);

        return $user;
    }

    public function resend(Session $session): void
    {
        $pending = Cache::get($this->cacheKey($session));

        if (! is_array($pending)) {
            throw new RuntimeException('Срок действия кода истёк. Запросите сброс пароля снова.');
        }

        $user = User::query()->find($pending['user_id'] ?? null);

        if ($user === null) {
            $this->clear($session);

            throw new RuntimeException('Срок действия кода истёк. Запросите сброс пароля снова.');
        }

        $code = (string) random_int(100000, 999999);
        $pending['code_hash'] = hash('sha256', $code);
        $pending['attempts'] = 0;

        Cache::put($this->cacheKey($session), $pending, now()->addMinutes($this->ttlMinutes()));
        $session->put(self::SESSION_KEY, $pending['hint'] ?? null);

        $this->deliverCode($user, $code);
    }

    public function reset(Session $session, string $code, string $password): User
    {
        $cacheKey = $this->cacheKey($session);
        $pending = Cache::get($cacheKey);

        if (! is_array($pending)) {
            throw new RuntimeException('Срок действия кода истёк. Запросите сброс пароля снова.');
        }

        $attempts = (int) ($pending['attempts'] ?? 0) + 1;

        if ($attempts > $this->maxAttempts()) {
            $this->clear($session);

            throw new RuntimeException('Превышено число попыток. Запросите сброс пароля снова.');
        }

        if (! hash_equals((string) $pending['code_hash'], hash('sha256', trim($code)))) {
            $pending['attempts'] = $attempts;
            Cache::put($cacheKey, $pending, now()->addMinutes($this->ttlMinutes()));

            throw new RuntimeException('Неверный код. Проверьте письмо или Telegram и попробуйте снова.');
        }

        $user = User::query()->find($pending['user_id'] ?? null);

        if ($user === null) {
            $this->clear($session);

            throw new RuntimeException('Срок действия кода истёк. Запросите сброс пароля снова.');
        }

        $user->update(['password' => $password]);
        $this->clear($session);

        return $user;
    }

    public function clear(Session $session): void
    {
        Cache::forget($this->cacheKey($session));
        $session->forget(self::SESSION_KEY);
    }

    private function deliverCode(User $user, string $code): void
    {
        if (filled($user->email)) {
            try {
                Mail::to($user->email)->send(
                    new RegistrationVerificationMail($code, $this->ttlMinutes(), 'password-reset'),
                );
            } catch (\Throwable $e) {
                Log::error('Не удалось отправить код сброса пароля на email', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);

                throw new RuntimeException('Не удалось отправить код на email. Попробуйте позже или обратитесь в студию.');
            }
        }

        if ($user->telegram_id !== null) {
            $sent = $this->telegram->send(
                (int) $user->telegram_id,
                '<b>Сброс пароля · ЭКО YOGA</b>'."\n\n"
                .'Код: <code>'.e($code).'</code>'."\n"
                .'Действует '.$this->ttlMinutes().' минут.',
            );

            if (! $sent && blank($user->email)) {
                throw new RuntimeException('Не удалось отправить код в Telegram. Попробуйте позже или обратитесь в студию.');
            }
        }
    }

    private function buildDeliveryHint(User $user): string
    {
        $parts = [];

        if (filled($user->email)) {
            $parts[] = $this->maskEmail($user->email);
        }

        if ($user->telegram_id !== null) {
            $parts[] = 'Telegram';
        }

        return implode(' и ', $parts);
    }

    private function maskEmail(string $email): string
    {
        if (! str_contains($email, '@')) {
            return $email;
        }

        [$local, $domain] = explode('@', $email, 2);
        $prefix = mb_substr($local, 0, 1);

        return $prefix.'***@'.$domain;
    }

    private function cacheKey(Session $session): string
    {
        return 'password_reset_pending:'.$session->getId();
    }
}
