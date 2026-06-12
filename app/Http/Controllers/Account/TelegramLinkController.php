<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Services\AdminActivityNotifier;
use App\Services\TelegramAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TelegramLinkController extends Controller
{
    public function __construct(
        protected TelegramAuthService $telegram,
        protected AdminActivityNotifier $adminActivity,
    ) {}

    public function callback(Request $request): RedirectResponse
    {
        if (! $this->telegram->isEnabled()) {
            return redirect()
                ->route('account')
                ->withErrors(['telegram' => 'Привязка Telegram временно недоступна.']);
        }

        $authData = $this->telegram->parseAndVerify($request->query());

        if ($authData === null) {
            return redirect()
                ->route('account')
                ->withErrors(['telegram' => 'Не удалось подтвердить Telegram. Попробуйте ещё раз.']);
        }

        $user = $request->user();

        if ($user->hasTelegram()) {
            return redirect()
                ->route('account')
                ->withErrors(['telegram' => 'Telegram уже привязан к вашему аккаунту.']);
        }

        if ($this->telegram->isLinkedToAnotherUser($authData, $user)) {
            return redirect()
                ->route('account')
                ->withErrors(['telegram' => 'Этот Telegram-аккаунт уже привязан к другому пользователю.']);
        }

        $this->telegram->linkUser($user, $authData);
        $user->refresh();

        $this->adminActivity->clientLinkedTelegram($user);

        return redirect()
            ->route('account')
            ->with('status', 'Telegram успешно привязан к вашему аккаунту.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user->hasTelegram()) {
            return redirect()
                ->route('account')
                ->withErrors(['telegram' => 'Telegram не привязан.']);
        }

        $this->adminActivity->clientUnlinkedTelegram($user);

        $this->telegram->unlinkUser($user);

        return redirect()
            ->route('account')
            ->with('status', 'Telegram отвязан от аккаунта.');
    }
}
