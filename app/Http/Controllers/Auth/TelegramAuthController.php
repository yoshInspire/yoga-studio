<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Auth\Concerns\RedirectsAuthenticatedUsers;
use App\Http\Controllers\Controller;
use App\Services\TelegramAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TelegramAuthController extends Controller
{
    use RedirectsAuthenticatedUsers;

    public function __construct(
        protected TelegramAuthService $telegram,
    ) {}

    public function callback(Request $request): RedirectResponse
    {
        if (! $this->telegram->isEnabled()) {
            return redirect()
                ->route('login')
                ->withErrors(['login' => 'Вход через Telegram временно недоступен.'], 'login');
        }

        $authData = $this->telegram->parseAndVerify($request->query());

        if ($authData === null) {
            return redirect()
                ->route('login')
                ->withErrors(['login' => 'Не удалось подтвердить вход через Telegram. Попробуйте ещё раз.'], 'login')
                ->with('auth_tab', 'login');
        }

        $user = $this->telegram->findUserByTelegramId($authData->id);

        if ($user !== null) {
            Auth::login($user);
            $request->session()->regenerate();
            $request->session()->forget('telegram_pending');

            return $this->redirectAuthenticated($user);
        }

        $request->session()->put('telegram_pending', $authData->toSession());

        return redirect()
            ->route('login')
            ->with('auth_tab', 'register')
            ->with('status', 'Telegram подтверждён: '.$authData->displayAccount().'. Заполните оставшиеся поля и завершите регистрацию.');
    }
}
