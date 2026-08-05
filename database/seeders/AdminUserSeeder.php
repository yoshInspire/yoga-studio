<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\PhoneNormalizer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ADMIN_EMAIL', 'admin@example.test');
        $phone = PhoneNormalizer::normalize(env('ADMIN_PHONE', '79000000000'));

        // Пароля по умолчанию нет намеренно: захардкоженный в открытом коде
        // пароль администратора — это готовый ключ от админки для всех, кто
        // видел репозиторий. Если ADMIN_PASSWORD не задан, учётка заводится
        // со случайным паролем, и войти в неё можно только через
        // восстановление по почте.
        $password = env('ADMIN_PASSWORD') ?: Str::password(32);

        // Только заводим учётку, если её ещё нет. Перезапись на каждом деплое
        // сбрасывала администратору имя, телефон и пароль на значения из env.
        User::query()->firstOrCreate(
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
