<?php

namespace Tests\Feature;

use App\Enums\ClassSessionStatus;
use App\Enums\SubscriptionType;
use App\Enums\UserRole;
use App\Models\ClassSession;
use App\Models\Subscription;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Студия перешла на формулировку «бронирование»: клиент бронирует место,
 * а не записывается. Тексты, которые видит клиент, не должны откатываться
 * к «записи» — это осознанное решение владельца студии.
 */
class BookingWordingTest extends TestCase
{
    use RefreshDatabase;

    private function client(): User
    {
        return User::create([
            'first_name' => 'Иван',
            'last_name' => 'Петров',
            'phone' => '+79990000001',
            'email' => 'client@example.test',
            'role' => UserRole::Client,
            'password' => 'secret123',
            'offer_accepted_at' => now(),
        ]);
    }

    public function test_schedule_page_uses_booking_wording(): void
    {
        $response = $this->get(route('schedule'));

        $response->assertOk();
        $response->assertSee('Правила бронирования');
        $response->assertSee('Как забронировать место');
        $response->assertSee('Отмена бронирования');
        $response->assertDontSee('Правила записи');
        $response->assertDontSee('Отмена записи');
    }

    public function test_account_page_uses_booking_wording(): void
    {
        $response = $this->actingAs($this->client())->get(route('account'));

        $response->assertOk();
        $response->assertSee('Мои бронирования');
        $response->assertDontSee('Мои записи');
    }

    public function test_client_gets_booking_wording_when_already_booked(): void
    {
        $user = $this->client();

        Subscription::create([
            'user_id' => $user->id,
            'type' => SubscriptionType::Group,
            'sessions_total' => 4,
            'sessions_used' => 0,
            'purchased_at' => now(),
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
        ]);

        $session = ClassSession::create([
            'topic' => 'Хатха-йога',
            'starts_at' => now()->addDay()->setTime(10, 0),
            'type' => SubscriptionType::Group,
            'capacity' => 6,
            'status' => ClassSessionStatus::Scheduled,
        ]);

        $service = app(BookingService::class);
        $service->book($user, $session);

        try {
            $service->book($user->fresh(), $session);
            $this->fail('Повторное бронирование должно отклоняться.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('забронировали', $e->getMessage());
            $this->assertStringNotContainsString('записаны', $e->getMessage());
        }
    }

    /** Кнопки в расписании рисует site.js — там формулировка тоже должна совпадать. */
    public function test_schedule_script_uses_booking_wording(): void
    {
        $js = File::get(public_path('js/site.js'));

        $this->assertStringContainsString('Забронировать', $js);
        $this->assertStringContainsString('Место забронировано', $js);
        $this->assertStringNotContainsString('>Записаться<', $js);
        $this->assertStringNotContainsString('>Вы записаны<', $js);
    }
}
