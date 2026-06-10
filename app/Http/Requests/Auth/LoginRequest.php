<?php

namespace App\Http\Requests\Auth;

use App\Support\PhoneNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class LoginRequest extends FormRequest
{
    protected $errorBag = 'login';

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
            'email' => ['nullable', 'string', 'email', 'max:255', 'required_without:phone'],
            'phone' => ['nullable', 'string', 'max:18', 'required_without:email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (filled($this->input('email')) && filled($this->input('phone'))) {
                $message = 'Укажите только email или только телефон.';
                $validator->errors()->add('email', $message);
                $validator->errors()->add('phone', $message);
            }

            if (filled($this->input('phone')) && PhoneNormalizer::normalize($this->input('phone')) === null) {
                $validator->errors()->add('phone', 'Введите корректный номер телефона.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required_without' => 'Укажите email или телефон.',
            'phone.required_without' => 'Укажите email или телефон.',
            'password.required' => 'Введите пароль.',
        ];
    }
}
