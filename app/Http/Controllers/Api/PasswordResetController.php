<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Mail\RegistrationVerificationMail;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\PhoneNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Сброс пароля клиента через API (код на email/Telegram). */
class PasswordResetController extends Controller
{
    public function __construct(private NotificationService $notifications) {}

    private function ttl(): int
    {
        return (int) config('studio.password_reset_ttl_minutes', 15);
    }

    private function maxAttempts(): int
    {
        return (int) config('studio.password_reset_max_attempts', 5);
    }

    private function key(string $flowId): string
    {
        return 'api_password_reset:'.$flowId;
    }

    /** Шаг 1: телефон → код на email/Telegram. */
    public function forgot(Request $request): JsonResponse
    {
        $request->validate(['phone' => ['required', 'string']]);
        $normalized = PhoneNormalizer::normalize($request->input('phone'));

        if ($normalized === null) {
            throw ValidationException::withMessages(['phone' => ['Введите корректный номер телефона.']]);
        }

        $user = User::query()->where('phone', $normalized)->where('role', UserRole::Client->value)->first();

        if ($user === null) {
            throw ValidationException::withMessages(['phone' => ['Клиент с таким телефоном не найден. Обратитесь в студию.']]);
        }

        if (blank($user->email) && $user->telegram_id === null) {
            throw ValidationException::withMessages(['phone' => ['Для восстановления нужен email или Telegram в профиле. Обратитесь в студию.']]);
        }

        $flowId = (string) Str::uuid();
        $code = (string) random_int(100000, 999999);

        Cache::put($this->key($flowId), [
            'user_id' => $user->id,
            'code_hash' => hash('sha256', $code),
            'attempts' => 0,
        ], now()->addMinutes($this->ttl()));

        $delivery = $this->deliverCode($user, $code);

        return response()->json([
            'flow_id' => $flowId,
            'hint' => $this->hint($user),
            'delivery' => $delivery,
            'message' => 'Код отправлен: '.$this->hint($user).'.',
        ]);
    }

    /** Шаг 2: код + новый пароль. */
    public function reset(Request $request): JsonResponse
    {
        $input = $request->validate([
            'flow_id' => ['required', 'string'],
            'code' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8'],
        ], ['password.min' => 'Пароль должен быть не короче 8 символов.']);

        $cacheKey = $this->key($input['flow_id']);
        $pending = Cache::get($cacheKey);

        if (! is_array($pending)) {
            throw ValidationException::withMessages(['code' => ['Срок действия кода истёк. Запросите сброс снова.']]);
        }

        $attempts = (int) ($pending['attempts'] ?? 0) + 1;
        if ($attempts > $this->maxAttempts()) {
            Cache::forget($cacheKey);
            throw ValidationException::withMessages(['code' => ['Превышено число попыток. Запросите сброс снова.']]);
        }

        if (! hash_equals((string) $pending['code_hash'], hash('sha256', trim($input['code'])))) {
            $pending['attempts'] = $attempts;
            Cache::put($cacheKey, $pending, now()->addMinutes($this->ttl()));
            throw ValidationException::withMessages(['code' => ['Неверный код. Проверьте письмо или Telegram.']]);
        }

        $user = User::query()->find($pending['user_id'] ?? null);
        if ($user === null) {
            Cache::forget($cacheKey);
            throw ValidationException::withMessages(['code' => ['Срок действия кода истёк. Запросите сброс снова.']]);
        }

        $user->update(['password' => $input['password']]);
        Cache::forget($cacheKey);

        return response()->json(['message' => 'Пароль изменён. Войдите с новым паролем.']);
    }

    /** @return array{email: bool, telegram: bool} */
    private function deliverCode(User $user, string $code): array
    {
        $delivery = ['email' => false, 'telegram' => false];

        if (filled($user->email)) {
            try {
                Mail::to($user->email)->send(new RegistrationVerificationMail($code, $this->ttl(), 'password-reset'));
                $delivery['email'] = true;
            } catch (\Throwable $e) {
                Log::error('API: не удалось отправить код сброса на email', ['error' => $e->getMessage()]);
            }
        }

        if ($user->telegram_id !== null) {
            $delivery['telegram'] = $this->notifications->notifyUserTelegram(
                $user,
                'Сброс пароля · ЭКО YOGA',
                ['Код: '.$code, 'Действует '.$this->ttl().' минут.'],
            );
        }

        if (! $delivery['email'] && ! $delivery['telegram']) {
            throw ValidationException::withMessages(['phone' => ['Не удалось отправить код. Обратитесь в студию.']]);
        }

        return $delivery;
    }

    private function hint(User $user): string
    {
        $parts = [];
        if (filled($user->email)) {
            $parts[] = 'email';
        }
        if ($user->telegram_id !== null) {
            $parts[] = 'Telegram';
        }

        return implode(' и ', $parts);
    }
}
