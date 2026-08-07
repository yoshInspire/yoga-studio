<?php

namespace App\Http\Requests\Account;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class DeleteAccountRequest extends FormRequest
{
    protected $errorBag = 'deleteAccount';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Пароль — защита от удаления посторонним, взявшим открытый телефон
     * или чужой компьютер. Галочка — от случайного нажатия.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'current_password'],
            'confirm' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.required' => 'Введите пароль.',
            'password.current_password' => 'Пароль указан неверно.',
            'confirm.accepted' => 'Подтвердите, что понимаете последствия удаления.',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        session()->flash('lk_section', 'profile');

        throw (new ValidationException($validator))->errorBag($this->errorBag);
    }
}
