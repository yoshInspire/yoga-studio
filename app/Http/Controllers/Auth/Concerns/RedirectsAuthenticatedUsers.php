<?php

namespace App\Http\Controllers\Auth\Concerns;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

trait RedirectsAuthenticatedUsers
{
    protected function redirectAuthenticated(User $user): RedirectResponse
    {
        return match ($user->role) {
            UserRole::Admin => redirect()->intended('/admin'),
            UserRole::Trainer => redirect()->intended(route('trainer')),
            default => redirect()->intended(route('account')),
        };
    }
}
