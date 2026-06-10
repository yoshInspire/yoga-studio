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
            $delivery = $this->passwordReset->start($request->session(), $request->validated('phone'));
        } catch (RuntimeException $e) {
            return back()
                ->withInput($request->only('phone'))
                ->withErrors(['phone' => $e->getMessage()], 'reset');
        }

        return back()
            ->with('auth_tab', 'reset-verify')
            ->with('status', $this->deliveryStatusMessage($delivery));
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
            $delivery = $this->passwordReset->resend($request->session());
        } catch (RuntimeException $e) {
            return back()
                ->withErrors(['code' => $e->getMessage()], 'reset-verify')
                ->with('auth_tab', 'reset-verify');
        }

        return back()
            ->with('auth_tab', 'reset-verify')
            ->with('status', $this->deliveryStatusMessage($delivery, resent: true));
    }

    public function cancel(Request $request): RedirectResponse
    {
        $this->passwordReset->clear($request->session());

        return back()
            ->with('auth_tab', 'login')
            ->with('status', 'Сброс пароля отменён.');
    }

    /**
     * @param  array{email: bool, telegram: bool}  $delivery
     */
    private function deliveryStatusMessage(array $delivery, bool $resent = false): string
    {
        $channels = array_filter([
            $delivery['email'] ? 'почту' : null,
            $delivery['telegram'] ? 'Telegram' : null,
        ]);

        if ($channels === []) {
            return $resent
                ? 'Не удалось отправить новый код. Попробуйте позже или обратитесь в студию.'
                : 'Не удалось отправить код. Попробуйте позже или обратитесь в студию.';
        }

        $prefix = $resent ? 'Новый код отправлен' : 'Код отправлен';

        return $prefix.' на '.implode(' и ', $channels).'.';
    }
}
