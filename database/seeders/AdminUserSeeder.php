<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\PhoneNormalizer;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ADMIN_EMAIL', 'admin@ekoyoga-ik.ru');
        $phone = PhoneNormalizer::normalize(env('ADMIN_PHONE', '79000000000'));
        $password = env('ADMIN_PASSWORD', 'StudioAdmin2026!');

        User::query()->updateOrCreate(
            ['email' => $email],
            [
                'first_name' => 'Администратор',
                'last_name' => 'Студии',
                'phone' => $phone,
                'role' => UserRole::Admin,
                'password' => $password,
            ],
        );
    }
}
