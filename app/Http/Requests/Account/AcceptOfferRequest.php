<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;

class AcceptOfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isClient() ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->boolean('offer_accepted')) {
            $this->merge(['offer_accepted' => null]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'offer_accepted' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'offer_accepted.accepted' => 'Необходимо согласие с договором-офертой.',
        ];
    }
}
