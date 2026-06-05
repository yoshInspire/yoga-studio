<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function create(): View|RedirectResponse
    {
        if (Auth::check()) {
            return $this->redirectAuthenticated(Auth::user());
        }

        return view('pages.login', [
            'activeTab' => session('auth_tab', 'login'),
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

    protected function redirectAuthenticated(User $user): RedirectResponse
    {
        return match ($user->role) {
            UserRole::Admin => redirect()->intended('/admin'),
            UserRole::Trainer => redirect()->intended(route('trainer')),
            default => redirect()->intended(route('account')),
        };
    }
}
