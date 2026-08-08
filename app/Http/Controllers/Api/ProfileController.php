<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Mail\RegistrationVerificationMail;
use App\Models\User;
use App\Services\AdminActivityNotifier;
use App\Support\OfferStorage;
use App\Support\PhoneNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    public function __construct(protected AdminActivityNotifier $adminActivity) {}

    private function ttl(): int
    {
        return (int) config('studio.profile_email_verification_ttl_minutes', 15);
    }

    private function emailKey(int $userId): string
    {
        return 'api_profile_email:'.$userId;
    }

    /** Обновление профиля (email меняется отдельным потоком с кодом). */
    public function update(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $phone = PhoneNormalizer::normalize($request->input('phone'));
        $request->merge(['phone' => $phone]);

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'patronymic' => ['nullable', 'string', 'max:100'],
            'birth_day' => ['required', 'integer', 'between:1,31'],
            'birth_month' => ['required', 'integer', 'between:1,12'],
            'birth_year' => ['nullable', 'integer', 'between:1920,2026'],
            'phone' => ['required', 'string', 'size:11', 'unique:users,phone,'.$user->id],
        ], ['phone.size' => 'Введите корректный номер телефона.', 'phone.unique' => 'Этот телефон уже занят.']);

        if ($phone === null) {
            throw ValidationException::withMessages(['phone' => ['Введите корректный номер телефона.']]);
        }

        $user->fill([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'patronymic' => $data['patronymic'] ?? null,
            'birth_day' => $data['birth_day'],
            'birth_month' => $data['birth_month'],
            'birth_year' => $data['birth_year'] ?? null,
            'phone' => $data['phone'],
        ]);
        $user->save();

        return response()->json(['user' => new UserResource($user), 'message' => 'Профиль обновлён.']);
    }

    /** Смена пароля (текущий + новый). */
    public function changePassword(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8'],
        ], ['password.min' => 'Новый пароль должен быть не короче 8 символов.']);

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => ['Текущий пароль неверный.']]);
        }

        $user->update(['password' => $data['password']]);

        // Профилем пользуются все роли, а оповещение написано про клиента:
        // «клиент сменил пароль» от самого администратора — только шум в Telegram.
        if ($user->isClient()) {
            $this->adminActivity->clientChangedPassword($user);
        }

        return response()->json(['message' => 'Пароль обновлён.']);
    }

    /** Запросить код для смены email. */
    public function requestEmailCode(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255', 'unique:users,email,'.$user->id],
        ], ['email.unique' => 'Этот email уже занят.']);

        $code = (string) random_int(100000, 999999);
        Cache::put($this->emailKey($user->id), [
            'email' => $data['email'],
            'code_hash' => hash('sha256', $code),
            'attempts' => 0,
        ], now()->addMinutes($this->ttl()));

        try {
            Mail::to($data['email'])->send(new RegistrationVerificationMail($code, $this->ttl()));
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Не удалось отправить код на email.'], 500);
        }

        return response()->json(['message' => 'Код отправлен на '.$data['email'].'.']);
    }

    /** Подтвердить код и сохранить новый email. */
    public function confirmEmail(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate(['code' => ['required', 'string']]);

        $cacheKey = $this->emailKey($user->id);
        $pending = Cache::get($cacheKey);

        if (! is_array($pending)) {
            throw ValidationException::withMessages(['code' => ['Срок действия кода истёк. Запросите новый.']]);
        }

        $attempts = (int) ($pending['attempts'] ?? 0) + 1;
        if ($attempts > 5) {
            Cache::forget($cacheKey);
            throw ValidationException::withMessages(['code' => ['Превышено число попыток.']]);
        }

        if (! hash_equals((string) $pending['code_hash'], hash('sha256', trim($data['code'])))) {
            $pending['attempts'] = $attempts;
            Cache::put($cacheKey, $pending, now()->addMinutes($this->ttl()));
            throw ValidationException::withMessages(['code' => ['Неверный код.']]);
        }

        $user->update(['email' => $pending['email'], 'email_verified_at' => now()]);
        Cache::forget($cacheKey);

        return response()->json(['user' => new UserResource($user), 'message' => 'Email обновлён.']);
    }

    /** Принять договор-оферту. */
    public function acceptOffer(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasAcceptedOffer()) {
            $user->update(['offer_accepted_at' => now()]);
            $this->adminActivity->clientAcceptedOffer($user);
        }

        return response()->json(['user' => new UserResource($user), 'message' => 'Согласие с офертой сохранено.']);
    }

    /** Статус и ссылка на оферту. */
    public function offer(Request $request): JsonResponse
    {
        return response()->json([
            'available' => OfferStorage::exists(),
            'accepted' => $request->user()->hasAcceptedOffer(),
        ]);
    }
}
