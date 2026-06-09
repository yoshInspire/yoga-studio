<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Auth\Concerns\RedirectsAuthenticatedUsers;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Services\TelegramAuthService;
use App\Support\TelegramAuthData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class LoginController extends Controller
{
    use RedirectsAuthenticatedUsers;

    public function __construct(
        protected TelegramAuthService $telegram,
    ) {}
    public function create(): View|RedirectResponse
    {
        if (Auth::check()) {
            return $this->redirectAuthenticated(Auth::user());
        }

        $telegramPending = session('telegram_pending');

        return view('pages.login', [
            'activeTab' => session('auth_tab', 'login'),
            'telegramEnabled' => $this->telegram->isEnabled(),
            'telegramBotUsername' => $this->telegram->botUsername(),
            'telegramPending' => is_array($telegramPending)
                ? TelegramAuthData::fromSession($telegramPending)
                : null,
        ]);
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $user = User::findByLogin($request->validated('login'));

        if ($user === null || ! Hash::check($request->validated('password'), $user->password)) {
            return back()
                ->withInput($request->only('login', 'remember'))
                ->withErrors(['login' => 'Неверный email, телефон или пароль.'], 'login')
                ->with('auth_tab', 'login');
        }

        Auth::login($user, $request->boolean('remember'));

        $request->session()->regenerate();

        return $this->redirectAuthenticated($user);
    }
}
