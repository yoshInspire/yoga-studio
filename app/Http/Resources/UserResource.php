<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'role' => $this->role->value,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'patronymic' => $this->patronymic,
            'full_name' => $this->fullName(),
            'short_name' => $this->shortName(),
            'initials' => $this->initials(),
            'email' => $this->email,
            'email_verified' => $this->email_verified_at !== null,
            'phone' => $this->phone,
            'phone_formatted' => $this->formattedPhone(),
            'birth_day' => $this->birth_day,
            'birth_month' => $this->birth_month,
            'birth_year' => $this->birth_year,
            'birth_date_formatted' => $this->formattedBirthDate(),
            'telegram_username' => $this->telegram_username,
            'telegram_display' => $this->telegramDisplayAccount(),
            'has_telegram' => $this->hasTelegram(),
            'offer_accepted' => $this->hasAcceptedOffer(),
            'offer_accepted_at' => $this->formattedOfferAcceptedAt(),
        ];
    }
}
