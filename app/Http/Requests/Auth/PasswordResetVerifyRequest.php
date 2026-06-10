<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class PasswordResetVerifyRequest extends FormRequest
{
    protected $errorBag = 'reset-verify';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'digits:6'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required' => 'Введите код из письма или Telegram.',
            'code.digits' => 'Код должен состоять из 6 цифр.',
            'password.min' => 'Пароль должен быть не короче 8 символов.',
            'password.confirmed' => 'Пароли не совпадают.',
        ];
    }

    protected function prepareForValidation(): void
    {
        session()->flash('auth_tab', 'reset-verify');
    }
}
