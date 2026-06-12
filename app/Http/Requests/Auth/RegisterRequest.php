<?php

namespace App\Http\Requests\Auth;

use App\Support\PhoneNormalizer;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class RegisterRequest extends FormRequest
{
    protected $errorBag = 'register';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $phone = PhoneNormalizer::normalize($this->input('phone'));

        if ($phone !== null) {
            $this->merge(['phone' => $phone]);
        }

        if ($this->filled('email')) {
            $this->merge(['email' => mb_strtolower(trim((string) $this->input('email')))]);
        }

        if (! $this->boolean('offer_accepted')) {
            $this->merge(['offer_accepted' => null]);
        }
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'patronymic' => ['nullable', 'string', 'max:100'],
            'birth_day' => ['required', 'integer', 'between:1,31'],
            'birth_month' => ['required', 'integer', 'between:1,12'],
            'birth_year' => ['nullable', 'integer', 'between:1920,2026'],
            'phone' => [
                'required',
                'string',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (PhoneNormalizer::normalize((string) $value) === null) {
                        $fail('Введите корректный номер телефона.');
                    }
                },
                'size:11',
                'unique:users,phone',
            ],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'offer_accepted' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function failedValidation(Validator $validator): void
    {
        session()->flash('auth_tab', 'register');

        throw (new ValidationException($validator))
            ->errorBag($this->errorBag)
            ->redirectTo(route('login'));
    }

    public function messages(): array
    {
        return [
            'first_name.required' => 'Укажите имя.',
            'last_name.required' => 'Укажите фамилию.',
            'birth_day.required' => 'Выберите день рождения.',
            'birth_month.required' => 'Выберите месяц рождения.',
            'phone.required' => 'Укажите телефон.',
            'phone.size' => 'Введите корректный номер телефона.',
            'phone.unique' => 'Этот телефон уже зарегистрирован.',
            'email.required' => 'Укажите email.',
            'email.email' => 'Введите корректный email.',
            'email.unique' => 'Этот email уже зарегистрирован.',
            'password.min' => 'Пароль должен быть не короче 8 символов.',
            'password.confirmed' => 'Пароли не совпадают.',
            'offer_accepted.accepted' => 'Необходимо согласие с договором-офертой.',
        ];
    }
}
