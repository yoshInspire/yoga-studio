<?php

namespace App\Http\Requests\Account;

use App\Support\PhoneNormalizer;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class UpdateProfileRequest extends FormRequest
{
    protected $errorBag = 'profile';

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
        } else {
            $this->merge(['email' => null]);
        }

        if (blank($this->input('patronymic'))) {
            $this->merge(['patronymic' => null]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $userId = $this->user()->id;

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
                'unique:users,phone,'.$userId,
            ],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email,'.$userId],
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
            'first_name.required' => 'Укажите имя.',
            'last_name.required' => 'Укажите фамилию.',
            'birth_day.required' => 'Выберите день рождения.',
            'birth_month.required' => 'Выберите месяц рождения.',
            'phone.required' => 'Укажите телефон.',
            'phone.size' => 'Введите корректный номер телефона.',
            'phone.unique' => 'Этот телефон уже зарегистрирован.',
            'email.email' => 'Введите корректный email.',
            'email.unique' => 'Этот email уже зарегистрирован.',
        ];
    }
}
