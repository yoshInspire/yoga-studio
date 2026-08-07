<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\DeleteAccountRequest;
use App\Models\User;
use App\Services\AccountDeletionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Удаление аккаунта из личного кабинета на сайте.
 *
 * Тот же сценарий есть в мобильном приложении (Api\AccountDeletionController):
 * магазины требуют возможность удалиться из самого приложения, а Google Play —
 * ещё и веб-ссылку, поэтому вход должен быть в обоих местах.
 */
class AccountDeletionController extends Controller
{
    public function __construct(private readonly AccountDeletionService $deletion) {}

    public function destroy(DeleteAccountRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->deletion->delete($user);

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()
            ->route('home')
            ->with('status', 'Аккаунт удалён. Данные профиля стёрты.');
    }
}
