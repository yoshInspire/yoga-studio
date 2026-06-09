<?php

namespace App\Http\Requests\Auth;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class VerifyRegistrationEmailRequest extends FormRequest
{
    protected $errorBag = 'verify';

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
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        session()->flash('auth_tab', 'verify-email');

        throw (new ValidationException($validator))
            ->errorBag($this->errorBag)
            ->redirectTo(route('login'));
    }

    public function messages(): array
    {
        return [
            'code.required' => 'Введите код из письма.',
            'code.regex' => 'Код должен состоять из 6 цифр.',
        ];
    }
}
