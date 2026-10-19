<?php

namespace Database\Seeders;

use App\Enums\ClassSessionStatus;
use App\Enums\SubscriptionType;
use App\Enums\UserRole;
use App\Models\ClassSession;
use App\Models\Subscription;
use App\Models\User;
use App\Services\FamilyService;
use Illuminate\Database\Seeder;

/**
 * Демо-семья для локальной проверки раздела «Семья» глазами.
 * ТОЛЬКО ДЛЯ ЛОКАЛЬНОЙ БАЗЫ — на проде не запускать.
 *
 *   php artisan db:seed --class=FamilyDemoSeeder
 *
 * Вход на сайт: телефоны ниже, пароль demo-family-2026.
 *  - Анна (глава, абонемент открыт Петру): +7 999 555-00-01
 *  - Пётр (участник, своего абонемента нет): +7 999 555-00-02
 *  - Мария (не в семье, для проверки приглашения): +7 999 555-00-03
 *
 * Фаза 2: Петру абонемент Анны открыт на 3 занятия; у Анны ребёнок Соня без
 * своего входа (Анна — представитель, записывает её из расписания).
 */
class FamilyDemoSeeder extends Seeder
{
    public const PASSWORD = 'demo-family-2026';

    public function run(FamilyService $family): void
    {
        if (app()->isProduction()) {
            $this->command?->error('Демо-семья только для локальной базы.');

            return;
        }

        $anna = $this->client('Анна', 'Демо', '79995550001');
        $petr = $this->client('Пётр', 'Демо', '79995550002');
        $this->client('Мария', 'Демо', '79995550003');

        $sub = Subscription::query()->firstOrCreate(
            ['user_id' => $anna->id, 'admin_note' => 'Демо семьи'],
            [
                'type' => SubscriptionType::Group,
                'sessions_total' => 8,
                'sessions_used' => 0,
                'purchased_at' => now()->subDays(2)->toDateString(),
                'starts_at' => now()->subDay()->toDateString(),
                'ends_at' => now()->addMonth()->toDateString(),
            ],
        );

        if ($family->membershipOf($anna) === null) {
            $family->accept($family->createInvite($anna), $petr);
        }

        $family->setShares($anna, $sub, [$petr->id => 3]);

        if (! $anna->wards()->exists()) {
            $family->addChild($anna, [
                'first_name' => 'Соня',
                'last_name' => 'Демо',
                'birth_date' => now()->subYears(10)->toDateString(),
                'health_note' => 'Сколиоз, без глубоких прогибов',
            ]);
        }

        // Приглашение для Марии — его код печатается ниже.
        $invite = $family->groupOf($anna)->invites()->pending()->first() ?? $family->createInvite($anna);

        ClassSession::query()->firstOrCreate(
            ['topic' => 'Демо семьи'],
            [
                'starts_at' => now()->addDays(2)->setTime(10, 0),
                'type' => SubscriptionType::Group,
                'capacity' => 6,
                'status' => ClassSessionStatus::Scheduled,
            ],
        );

        $this->command?->info('Приглашение для Марии: '.$family->inviteUrl($invite));
    }

    private function client(string $first, string $last, string $phone): User
    {
        return User::query()->firstOrCreate(
            ['phone' => $phone],
            [
                'first_name' => $first,
                'last_name' => $last,
                'role' => UserRole::Client,
                'password' => self::PASSWORD,
                'offer_accepted_at' => now(),
            ],
        );
    }
}
