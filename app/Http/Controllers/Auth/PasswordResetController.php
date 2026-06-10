<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Auth\Concerns\RedirectsAuthenticatedUsers;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\PasswordResetVerifyRequest;
use App\Services\PasswordResetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

class PasswordResetController extends Controller
{
    use RedirectsAuthenticatedUsers;

    public function __construct(
        protected PasswordResetService $passwordReset,
    ) {}

    public function request(ForgotPasswordRequest $request): RedirectResponse
    {
        try {
            $this->passwordReset->start($request->session(), $request->validated('phone'));
        } catch (RuntimeException $e) {
            return back()
                ->withInput($request->only('phone'))
                ->withErrors(['phone' => $e->getMessage()], 'reset');
        }

        return back()
            ->with('auth_tab', 'reset-verify')
            ->with('status', 'Код отправлен. Проверьте почту (и Telegram, если он привязан в личном кабинете).');
    }

    public function verify(PasswordResetVerifyRequest $request): RedirectResponse
    {
        try {
            $user = $this->passwordReset->reset(
                $request->session(),
                $request->validated('code'),
                $request->validated('password'),
            );
        } catch (RuntimeException $e) {
            return back()
                ->withInput($request->only('code'))
                ->withErrors(['code' => $e->getMessage()], 'reset-verify')
                ->with('auth_tab', 'reset-verify');
        }

        Auth::login($user);
        $request->session()->regenerate();

        return $this->redirectAuthenticated($user)
            ->with('status', 'Пароль обновлён. Добро пожаловать в личный кабинет!');
    }

    public function resend(Request $request): RedirectResponse
    {
        try {
            $this->passwordReset->resend($request->session());
        } catch (RuntimeException $e) {
            return back()
                ->withErrors(['code' => $e->getMessage()], 'reset-verify')
                ->with('auth_tab', 'reset-verify');
        }

        return back()
            ->with('auth_tab', 'reset-verify')
            ->with('status', 'Новый код отправлен на email (и в Telegram, если привязан).');
    }

    public function cancel(Request $request): RedirectResponse
    {
        $this->passwordReset->clear($request->session());

        return back()
            ->with('auth_tab', 'login')
            ->with('status', 'Сброс пароля отменён.');
    }
}
