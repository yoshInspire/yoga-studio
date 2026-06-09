<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use App\Services\TelegramAuthService;
use App\Support\TelegramAuthData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class RegisterController extends Controller
{
    public function __construct(
        protected TelegramAuthService $telegram,
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

        $telegramPending = session('telegram_pending');
        $telegramData = is_array($telegramPending)
            ? TelegramAuthData::fromSession($telegramPending)
            : null;

        if ($telegramData !== null && $this->telegram->isLinkedToAnotherUser($telegramData)) {
            return back()
                ->withInput()
                ->withErrors(['telegram' => 'Этот Telegram-аккаунт уже привязан к другому пользователю.'], 'register')
                ->with('auth_tab', 'register');
        }

        $user = User::query()->create([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'patronymic' => $data['patronymic'] ?? null,
            'birth_day' => $data['birth_day'],
            'birth_month' => $data['birth_month'],
            'birth_year' => $data['birth_year'] ?? null,
            'phone' => $data['phone'],
            'email' => $data['email'] ?? null,
            'password' => $data['password'],
            'role' => UserRole::Client,
            'telegram_id' => $telegramData?->id,
            'telegram_username' => $telegramData?->username,
            'telegram_linked_at' => $telegramData !== null ? now() : null,
        ]);

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->forget('telegram_pending');

        return redirect()
            ->route('account')
            ->with('status', 'Регистрация прошла успешно. Добро пожаловать в личный кабинет!');
    }
}
