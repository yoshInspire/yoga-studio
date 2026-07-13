<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Mail\RegistrationVerificationMail;
use App\Models\User;
use App\Services\AdminActivityNotifier;
use App\Support\PhoneNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Регистрация клиента через API с подтверждением email-кодом.
 * Состояние хранится в Cache по flow_id (аналог сессии на сайте).
 */
class RegisterController extends Controller
{
    public function __construct(
        protected AdminActivityNotifier $adminActivity,
    ) {}

    private function ttl(): int
    {
        return (int) config('studio.registration_email_verification_ttl_minutes', 15);
    }

    private function maxAttempts(): int
    {
        return (int) config('studio.registration_email_verification_max_attempts', 5);
    }

    private function key(string $flowId): string
    {
        return 'api_registration:'.$flowId;
    }

    /** Шаг 1: приём данных, отправка кода. */
    public function start(Request $request): JsonResponse
    {
        $normalizedPhone = PhoneNormalizer::normalize($request->input('phone'));
        $request->merge(['phone' => $normalizedPhone]);
        if ($request->filled('email')) {
            $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        }

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'patronymic' => ['nullable', 'string', 'max:100'],
            'birth_day' => ['required', 'integer', 'between:1,31'],
            'birth_month' => ['required', 'integer', 'between:1,12'],
            'birth_year' => ['nullable', 'integer', 'between:1920,2026'],
            'phone' => ['required', 'string', 'size:11', 'unique:users,phone'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'offer_accepted' => ['accepted'],
        ], [
            'phone.size' => 'Введите корректный номер телефона.',
            'phone.unique' => 'Этот телефон уже зарегистрирован.',
            'email.unique' => 'Этот email уже зарегистрирован.',
            'password.min' => 'Пароль должен быть не короче 8 символов.',
            'offer_accepted.accepted' => 'Необходимо согласие с договором-офертой.',
        ]);

        if ($normalizedPhone === null) {
            throw ValidationException::withMessages(['phone' => ['Введите корректный номер телефона.']]);
        }

        $flowId = (string) Str::uuid();
        $code = (string) random_int(100000, 999999);

        Cache::put($this->key($flowId), [
            'email' => $data['email'],
            'code_hash' => hash('sha256', $code),
            'attempts' => 0,
            'data' => [
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'patronymic' => $data['patronymic'] ?? null,
                'birth_day' => $data['birth_day'],
                'birth_month' => $data['birth_month'],
                'birth_year' => $data['birth_year'] ?? null,
                'phone' => $data['phone'],
                'email' => $data['email'],
                'password' => Crypt::encryptString((string) $data['password']),
            ],
        ], now()->addMinutes($this->ttl()));

        try {
            Mail::to($data['email'])->send(new RegistrationVerificationMail($code, $this->ttl()));
        } catch (\Throwable $e) {
            Cache::forget($this->key($flowId));
            Log::error('API: не удалось отправить код регистрации', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Не удалось отправить код на email. Попробуйте позже.'], 500);
        }

        return response()->json([
            'flow_id' => $flowId,
            'email' => $data['email'],
            'message' => 'Код подтверждения отправлен на '.$data['email'].'.',
        ]);
    }

    /** Шаг 2: подтверждение кода, создание аккаунта, выдача токена. */
    public function verify(Request $request): JsonResponse
    {
        $input = $request->validate([
            'flow_id' => ['required', 'string'],
            'code' => ['required', 'string'],
        ]);

        $cacheKey = $this->key($input['flow_id']);
        $pending = Cache::get($cacheKey);

        if (! is_array($pending)) {
            throw ValidationException::withMessages(['code' => ['Срок действия кода истёк. Заполните форму снова.']]);
        }

        $attempts = (int) ($pending['attempts'] ?? 0) + 1;
        if ($attempts > $this->maxAttempts()) {
            Cache::forget($cacheKey);
            throw ValidationException::withMessages(['code' => ['Превышено число попыток. Заполните форму снова.']]);
        }

        if (! hash_equals((string) $pending['code_hash'], hash('sha256', trim($input['code'])))) {
            $pending['attempts'] = $attempts;
            Cache::put($cacheKey, $pending, now()->addMinutes($this->ttl()));
            throw ValidationException::withMessages(['code' => ['Неверный код. Проверьте письмо или запросите новый.']]);
        }

        $data = $pending['data'];

        if (User::query()->where('phone', $data['phone'])->exists()) {
            Cache::forget($cacheKey);
            throw ValidationException::withMessages(['phone' => ['Этот телефон уже зарегистрирован.']]);
        }
        if (User::query()->where('email', $data['email'])->exists()) {
            Cache::forget($cacheKey);
            throw ValidationException::withMessages(['email' => ['Этот email уже зарегистрирован.']]);
        }

        $user = User::query()->create([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'patronymic' => $data['patronymic'] ?? null,
            'birth_day' => $data['birth_day'],
            'birth_month' => $data['birth_month'],
            'birth_year' => $data['birth_year'] ?? null,
            'phone' => $data['phone'],
            'email' => $data['email'],
            'email_verified_at' => now(),
            'password' => Crypt::decryptString((string) $data['password']),
            'role' => UserRole::Client,
            'offer_accepted_at' => now(),
        ]);

        Cache::forget($cacheKey);
        $this->adminActivity->clientRegistered($user, viaTelegram: false);

        $token = $user->createToken('mobile')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => new UserResource($user),
        ]);
    }

    /** Повторная отправка кода. */
    public function resend(Request $request): JsonResponse
    {
        $input = $request->validate(['flow_id' => ['required', 'string']]);
        $cacheKey = $this->key($input['flow_id']);
        $pending = Cache::get($cacheKey);

        if (! is_array($pending)) {
            throw ValidationException::withMessages(['flow_id' => ['Срок действия истёк. Заполните форму снова.']]);
        }

        $code = (string) random_int(100000, 999999);
        $pending['code_hash'] = hash('sha256', $code);
        $pending['attempts'] = 0;
        Cache::put($cacheKey, $pending, now()->addMinutes($this->ttl()));

        try {
            Mail::to($pending['email'])->send(new RegistrationVerificationMail($code, $this->ttl()));
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Не удалось отправить код.'], 500);
        }

        return response()->json(['message' => 'Новый код отправлен на '.$pending['email'].'.']);
    }
}
