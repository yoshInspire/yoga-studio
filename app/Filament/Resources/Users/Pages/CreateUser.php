<?php

namespace App\Filament\Resources\Users\Pages;

use App\Enums\UserRole;
use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $role = $data['role'] ?? UserRole::Client->value;

        if (in_array($role, [UserRole::Client->value, UserRole::Trainer->value], true)) {
            $data['password'] = Str::password(10, letters: true, numbers: true, symbols: false);
        }

        return $data;
    }
}
