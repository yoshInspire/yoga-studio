<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\SendProfileEmailCodeRequest;
use App\Http\Requests\Account\UpdateProfileRequest;
use App\Http\Requests\Account\VerifyProfileEmailCodeRequest;
use App\Models\User;
use App\Services\ProfileEmailVerificationService;
use Illuminate\Http\RedirectResponse;

class ProfileController extends Controller
{
    public function __construct(
        protected ProfileEmailVerificationService $emailVerification,
    ) {}

    public function update(UpdateProfileRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validated();

        $newEmail = $data['email'] ?? null;
        $currentEmail = $user->email;

        if ($this->emailChangeRequiresVerification($currentEmail, $newEmail)) {
            if (! $this->emailVerification->isVerified($user, (string) $newEmail)) {
                return redirect()
                    ->route('account')
                    ->withErrors(['email' => 'Подтвердите email перед сохранением изменений.'], 'profile')
                    ->with('profile_edit_open', true)
                    ->with('lk_section', 'profile')
                    ->withInput();
            }
        }

        $user->fill([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'patronymic' => $data['patronymic'] ?? null,
            'birth_day' => $data['birth_day'],
            'birth_month' => $data['birth_month'],
            'birth_year' => $data['birth_year'] ?? null,
            'phone' => $data['phone'],
            'email' => $newEmail,
        ]);

        if ($newEmail !== $currentEmail) {
            $user->email_verified_at = $newEmail ? now() : null;
        }

        $user->save();

        $this->emailVerification->clear($user);

        return redirect()
            ->route('account')
            ->with('status', 'Профиль обновлён.')
            ->with('lk_section', 'profile');
    }

    public function sendEmailCode(SendProfileEmailCodeRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $email = $request->validated('email');

        if (! $this->emailChangeRequiresVerification($user->email, $email)) {
            return redirect()
                ->route('account')
                ->with('status', 'Этот email уже указан в профиле.')
                ->with('profile_edit_open', true)
                ->with('lk_section', 'profile')
                ->withInput($request->except('password'));
        }

        try {
            $this->emailVerification->sendCode($user, $email);
        } catch (\RuntimeException $e) {
            return redirect()
                ->route('account')
                ->withErrors(['email' => $e->getMessage()], 'profile')
                ->with('profile_edit_open', true)
                ->with('lk_section', 'profile')
                ->withInput($request->except('password'));
        }

        return redirect()
            ->route('account')
            ->with('status', 'Код подтверждения отправлен на '.$email.'.')
            ->with('profile_edit_open', true)
            ->with('profile_code_sent', true)
            ->with('lk_section', 'profile')
            ->withInput($request->except('password'));
    }

    public function verifyEmailCode(VerifyProfileEmailCodeRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $email = $request->validated('email');

        $pendingEmail = $this->emailVerification->pendingEmail($user);
        if ($pendingEmail !== null && $pendingEmail !== $email) {
            return redirect()
                ->route('account')
                ->withErrors(['code' => 'Email не совпадает с адресом, на который отправлен код.'], 'profile')
                ->with('profile_edit_open', true)
                ->with('lk_section', 'profile')
                ->withInput($request->except('password', 'code'));
        }

        try {
            $verifiedEmail = $this->emailVerification->verifyCode($user, $request->validated('code'));
        } catch (\RuntimeException $e) {
            return redirect()
                ->route('account')
                ->withErrors(['code' => $e->getMessage()], 'profile')
                ->with('profile_edit_open', true)
                ->with('lk_section', 'profile')
                ->withInput($request->except('password', 'code'));
        }

        if ($verifiedEmail !== $email) {
            return redirect()
                ->route('account')
                ->withErrors(['code' => 'Код не соответствует указанному email.'], 'profile')
                ->with('profile_edit_open', true)
                ->with('lk_section', 'profile')
                ->withInput($request->except('password', 'code'));
        }

        return redirect()
            ->route('account')
            ->with('profile_edit_open', true)
            ->with('profile_email_verified', $verifiedEmail)
            ->with('lk_section', 'profile')
            ->withInput($request->except('password', 'code'));
    }

    private function emailChangeRequiresVerification(?string $currentEmail, ?string $newEmail): bool
    {
        $current = $currentEmail !== null ? mb_strtolower(trim($currentEmail)) : null;
        $new = $newEmail !== null ? mb_strtolower(trim($newEmail)) : null;

        if ($new === null) {
            return false;
        }

        return $new !== $current;
    }
}
