<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\VerifyRegistrationEmailRequest;
use App\Models\User;
use App\Services\RegistrationEmailVerificationService;
use App\Services\TelegramAuthService;
use App\Support\TelegramAuthData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class RegisterController extends Controller
{
    public function __construct(
        protected TelegramAuthService $telegram,
        protected RegistrationEmailVerificationService $emailVerification,
    ) {}

    public function create(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('account');
        }

        return view('pages.login', [
            'activeTab' => 'register',
        ]);
    }

    public function store(RegisterRequest $request): RedirectResponse
    {
        $data = $request->validated();

        if (blank($data['patronymic'] ?? null)) {
            $data['patronymic'] = null;
        }

        $telegramData = $this->telegramDataFromSession($request);

        if ($telegramData !== null && $this->telegram->isLinkedToAnotherUser($telegramData)) {
            return back()
                ->withInput()
                ->withErrors(['telegram' => 'Этот Telegram-аккаунт уже привязан к другому пользователю.'], 'register')
                ->with('auth_tab', 'register');
        }

        if ($telegramData !== null) {
            return $this->completeRegistration($request, $data, $telegramData);
        }

        try {
            $this->emailVerification->start($request->session(), $data);
        } catch (\RuntimeException $e) {
            return back()
                ->withInput()
                ->withErrors(['email' => $e->getMessage()], 'register')
                ->with('auth_tab', 'register');
        }

        return redirect()
            ->route('login')
            ->with('auth_tab', 'verify-email')
            ->with('status', 'Код подтверждения отправлен на '.$data['email'].'. Введите его ниже.');
    }

    public function verify(VerifyRegistrationEmailRequest $request): RedirectResponse
    {
        if (! $this->emailVerification->hasPending($request->session())) {
            return redirect()
                ->route('login')
                ->withErrors(['code' => 'Срок действия кода истёк. Заполните форму регистрации снова.'], 'verify')
                ->with('auth_tab', 'register');
        }

        try {
            $data = $this->emailVerification->verify($request->session(), $request->validated('code'));
        } catch (\RuntimeException $e) {
            return back()
                ->withErrors(['code' => $e->getMessage()], 'verify')
                ->with('auth_tab', 'verify-email');
        }

        if (User::query()->where('phone', $data['phone'])->exists()) {
            return redirect()
                ->route('login')
                ->withErrors(['phone' => 'Этот телефон уже зарегистрирован.'], 'register')
                ->with('auth_tab', 'register');
        }

        if (User::query()->where('email', $data['email'])->exists()) {
            return redirect()
                ->route('login')
                ->withErrors(['email' => 'Этот email уже зарегистрирован.'], 'register')
                ->with('auth_tab', 'register');
        }

        return $this->completeRegistration($request, $data, null, verifiedEmail: true);
    }

    public function resend(Request $request): RedirectResponse
    {
        $cacheKey = 'registration_email_pending:'.$request->session()->getId();
        $pending = cache()->get($cacheKey);

        if (! is_array($pending) || ! isset($pending['data'])) {
            return redirect()
                ->route('login')
                ->withErrors(['code' => 'Срок действия кода истёк. Заполните форму регистрации снова.'], 'verify')
                ->with('auth_tab', 'register');
        }

        $data = $pending['data'];
        try {
            $data['password'] = \Illuminate\Support\Facades\Crypt::decryptString((string) $data['password']);
        } catch (\Throwable $e) {
            Log::error('Не удалось прочитать данные регистрации для повторной отправки кода', [
                'error' => $e->getMessage(),
            ]);

            $this->emailVerification->clear($request->session());

            return redirect()
                ->route('login')
                ->withErrors(['code' => 'Срок действия кода истёк. Заполните форму регистрации снова.'], 'verify')
                ->with('auth_tab', 'register');
        }

        try {
            $this->emailVerification->start($request->session(), $data);
        } catch (\RuntimeException $e) {
            return back()
                ->withErrors(['code' => $e->getMessage()], 'verify')
                ->with('auth_tab', 'verify-email');
        }

        return back()
            ->with('status', 'Новый код отправлен на '.$data['email'].'.')
            ->with('auth_tab', 'verify-email');
    }

    public function cancelVerification(Request $request): RedirectResponse
    {
        $this->emailVerification->clear($request->session());

        return redirect()
            ->route('login')
            ->with('auth_tab', 'register')
            ->with('status', 'Регистрация отменена. Вы можете заполнить форму снова.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function completeRegistration(
        Request $request,
        array $data,
        ?TelegramAuthData $telegramData = null,
        bool $verifiedEmail = false,
    ): RedirectResponse {
        $user = User::query()->create([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'patronymic' => $data['patronymic'] ?? null,
            'birth_day' => $data['birth_day'],
            'birth_month' => $data['birth_month'],
            'birth_year' => $data['birth_year'] ?? null,
            'phone' => $data['phone'],
            'email' => $data['email'] ?? null,
            'email_verified_at' => $verifiedEmail ? now() : null,
            'password' => $data['password'],
            'role' => UserRole::Client,
            'telegram_id' => $telegramData?->id,
            'telegram_username' => $telegramData?->username,
            'telegram_linked_at' => $telegramData !== null ? now() : null,
        ]);

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->forget('telegram_pending');
        $this->emailVerification->clear($request->session());

        return redirect()
            ->route('account')
            ->with('status', 'Регистрация прошла успешно. Добро пожаловать в личный кабинет!');
    }

    private function telegramDataFromSession(Request $request): ?TelegramAuthData
    {
        $telegramPending = $request->session()->get('telegram_pending');

        return is_array($telegramPending)
            ? TelegramAuthData::fromSession($telegramPending)
            : null;
    }
}
