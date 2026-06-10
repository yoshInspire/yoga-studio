<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\ChangePasswordRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

class PasswordController extends Controller
{
    public function update(ChangePasswordRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->update([
            'password' => $request->validated('password'),
        ]);

        return redirect()
            ->route('account')
            ->with('status', 'Пароль обновлён.')
            ->with('lk_section', 'profile');
    }
}
