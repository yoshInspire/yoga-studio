<?php

namespace Database\Seeders;

use App\Enums\ClassSessionStatus;
use App\Enums\SubscriptionType;
use App\Enums\UserRole;
use App\Models\ClassSession;
use App\Models\Direction;
use App\Models\News;
use App\Models\Subscription;
use App\Models\User;
use App\Services\BookingService;
use App\Support\PhoneNormalizer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Демо-данные для тестирования мобильного прототипа:
 * клиент, абонементы, расписание на неделю, записи, новости.
 * Идемпотентно (updateOrCreate + пере-создание сессий текущей недели).
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            DirectionSeeder::class,
            AdminUserSeeder::class,
            TrainerUserSeeder::class,
        ]);

        $trainer = User::query()->where('role', UserRole::Trainer)->first();

        // Профиль тренера на сайте
        $trainer?->update([
            'trainer_title' => 'Сертифицированный инструктор хатха- и виньяса-йоги',
            'trainer_bio' => 'Ведёт групповые и индивидуальные занятия. Опыт преподавания 8 лет.',
            'show_on_site' => true,
            'site_sort_order' => 1,
        ]);

        // Демо-клиент
        $client = User::query()->updateOrCreate(
            ['phone' => PhoneNormalizer::normalize('79001234567')],
            [
                'first_name' => 'Ирина',
                'last_name' => 'Тестовая',
                'patronymic' => 'Петровна',
                'email' => 'client@ekoyoga-ik.ru',
                'email_verified_at' => now(),
                'birth_day' => 15,
                'birth_month' => 6,
                'birth_year' => 1990,
                'role' => UserRole::Client,
                'password' => 'Client2026!',
                'offer_accepted_at' => now(),
            ],
        );

        // Абонементы: групповой (частично использован) + индивидуальный
        Subscription::query()->updateOrCreate(
            ['user_id' => $client->id, 'type' => SubscriptionType::Group],
            [
                'sessions_total' => 8,
                'sessions_used' => 3,
                'sessions_per_day' => 1,
                'purchased_at' => now()->subDays(10),
                'starts_at' => now()->subDays(10),
                'ends_at' => now()->addDays(20),
                'admin_note' => 'Демо-абонемент (групповой)',
            ],
        );

        Subscription::query()->updateOrCreate(
            ['user_id' => $client->id, 'type' => SubscriptionType::Individual],
            [
                'sessions_total' => 4,
                'sessions_used' => 0,
                'sessions_per_day' => 1,
                'purchased_at' => now()->subDays(2),
                'starts_at' => now()->subDays(2),
                'ends_at' => now()->addDays(28),
                'admin_note' => 'Демо-абонемент (индивидуальный)',
            ],
        );

        // Расписание на ближайшую неделю
        $directions = Direction::query()->orderBy('sort_order')->get();
        $hatha = $directions->firstWhere('slug', 'hatha') ?? $directions->get(0);
        $vinyasa = $directions->firstWhere('slug', 'vinyasa') ?? $directions->get(2);
        $yin = $directions->firstWhere('slug', 'yin') ?? $directions->get(4);

        // Чистим будущие незабронированные демо-сессии, чтобы не плодить дубли
        ClassSession::query()
            ->whereBetween('starts_at', [now()->startOfDay(), now()->addDays(7)->endOfDay()])
            ->doesntHave('bookings')
            ->delete();

        $plan = [
            ['dir' => $hatha, 'topic' => 'Мягкая практика для всех уровней', 'hour' => 9, 'type' => SubscriptionType::Group],
            ['dir' => $vinyasa, 'topic' => 'Динамичный поток', 'hour' => 19, 'type' => SubscriptionType::Group],
            ['dir' => $yin, 'topic' => 'Глубокое расслабление', 'hour' => 20, 'type' => SubscriptionType::Group],
        ];

        for ($day = 0; $day <= 6; $day++) {
            $date = Carbon::today()->addDays($day);

            foreach ($plan as $i => $slot) {
                // Чуть разрежаем: не все занятия каждый день
                if (($day + $i) % 3 === 2) {
                    continue;
                }

                if (! $slot['dir']) {
                    continue;
                }

                ClassSession::query()->create([
                    'direction_id' => $slot['dir']->id,
                    'topic' => $slot['topic'],
                    'starts_at' => $date->copy()->setTime($slot['hour'], 0),
                    'duration_minutes' => 90,
                    'type' => $slot['type'],
                    'capacity' => 6,
                    'trainer_id' => $trainer?->id,
                    'status' => ClassSessionStatus::Scheduled,
                ]);
            }
        }

        // Записываем клиента на пару ближайших занятий через сервис (корректное списание)
        $bookings = app(BookingService::class);
        $upcoming = ClassSession::query()
            ->where('type', SubscriptionType::Group)
            ->where('starts_at', '>', now()->addHours(1))
            ->where('status', ClassSessionStatus::Scheduled)
            ->orderBy('starts_at')
            ->limit(2)
            ->get();

        foreach ($upcoming as $session) {
            try {
                $bookings->book($client, $session);
            } catch (\Throwable $e) {
                // пропускаем, если бизнес-правило не позволяет
            }
        }

        // Новости
        $newsItems = [
            [
                'title' => 'Открыт набор в утренние группы хатха-йоги',
                'excerpt' => 'Спокойные практики по будням в 9:00. Идеально для начала дня.',
                'body' => "С этой недели открываем дополнительные утренние группы хатха-йоги.\n\nЗанятия проходят в мягком темпе и подходят для любого уровня подготовки. Записаться можно в личном кабинете.",
            ],
            [
                'title' => 'Мастер-класс по йога-нидре',
                'excerpt' => 'Практика глубокого расслабления и восстановления нервной системы.',
                'body' => "Приглашаем на мастер-класс по йога-нидре — технике осознанного расслабления.\n\nМероприятие проходит вне абонемента, оплачивается отдельно. Количество мест ограничено.",
            ],
            [
                'title' => 'Обновили студийное пространство',
                'excerpt' => 'Новый инвентарь, коврики и зона отдыха для наших гостей.',
                'body' => "Мы обновили пространство студии: добавили новый инвентарь, коврики премиум-класса и уютную зону отдыха.\n\nЖдём вас на занятиях!",
            ],
        ];

        foreach ($newsItems as $i => $item) {
            News::query()->updateOrCreate(
                ['slug' => \Illuminate\Support\Str::slug($item['title'])],
                [
                    'title' => $item['title'],
                    'excerpt' => $item['excerpt'],
                    'body' => $item['body'],
                    'is_published' => true,
                    'published_at' => now()->subDays(($i + 1) * 2),
                ],
            );
        }
    }
}
