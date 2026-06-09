<?php

namespace App\Http\Requests\Account;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class VerifyProfileEmailCodeRequest extends FormRequest
{
    protected $errorBag = 'profile';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('email')) {
            $this->merge(['email' => mb_strtolower(trim((string) $this->input('email')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        session()->flash('profile_edit_open', true);
        session()->flash('lk_section', 'profile');

        throw (new ValidationException($validator))
            ->errorBag($this->errorBag)
            ->redirectTo(route('account'));
    }

    public function messages(): array
    {
        return [
            'email.required' => 'Укажите email.',
            'code.required' => 'Введите код из письма.',
            'code.regex' => 'Код должен состоять из 6 цифр.',
        ];
    }
}
