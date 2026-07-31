<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\PhoneNormalizer;
use Illuminate\Database\Seeder;

class TrainerUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('TRAINER_EMAIL', 'trainer@ekoyoga-ik.ru');
        $phone = PhoneNormalizer::normalize(env('TRAINER_PHONE', '79000000001'));
        $password = env('TRAINER_PASSWORD', 'StudioTrainer2026!');

        // Только заводим учётку, если её ещё нет: иначе деплой сбрасывал
        // тренеру имя, телефон и пароль на значения из env.
        User::query()->firstOrCreate(
            ['email' => $email],
            [
                'first_name' => 'Александр',
                'last_name' => 'Тренер',
                'phone' => $phone,
                'role' => UserRole::Trainer,
                'password' => $password,
            ],
        );
    }
}
