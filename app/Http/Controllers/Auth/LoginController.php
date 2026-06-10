<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Auth\Concerns\RedirectsAuthenticatedUsers;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Services\RegistrationEmailVerificationService;
use App\Services\TelegramAuthService;
use App\Support\TelegramAuthData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class LoginController extends Controller
{
    use RedirectsAuthenticatedUsers;

    public function __construct(
        protected TelegramAuthService $telegram,
        protected RegistrationEmailVerificationService $emailVerification,
    ) {}

    public function create(Request $request): View|RedirectResponse
    {
        if (Auth::check()) {
            return $this->redirectAuthenticated(Auth::user());
        }

        $telegramPending = $request->session()->get('telegram_pending');

        $activeTab = $request->session()->get('auth_tab', 'login');
        if ($activeTab !== 'verify-email' && $this->emailVerification->hasPending($request->session())) {
            $activeTab = 'verify-email';
        }

        return view('pages.login', [
            'activeTab' => $activeTab,
            'telegramEnabled' => $this->telegram->isEnabled(),
            'telegramBotUsername' => $this->telegram->botUsername(),
            'telegramPending' => is_array($telegramPending)
                ? TelegramAuthData::fromSession($telegramPending)
                : null,
            'verificationEmail' => $this->emailVerification->pendingEmail($request->session()),
        ]);
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $user = User::findByPhone($request->validated('phone'));

        if ($user === null || ! Hash::check($request->validated('password'), $user->password)) {
            return back()
                ->withInput($request->only('phone', 'remember'))
                ->withErrors(['phone' => 'Неверный телефон или пароль.'], 'login')
                ->with('auth_tab', 'login');
        }

        Auth::login($user, $request->boolean('remember'));

        $request->session()->regenerate();

        return $this->redirectAuthenticated($user);
    }
}
