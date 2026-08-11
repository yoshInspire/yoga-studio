<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\ClassSessionStatus;
use App\Enums\SubscriptionType;
use App\Enums\UserRole;
use App\Models\ClassSession;
use App\Models\Subscription;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Отмена брони на сайте.
 *
 * Метод контроллера обращался к $request, не получив его параметром, — бронь
 * отменялась, а клиенту прилетала пятисотка. Тест закрывает именно путь через
 * маршрут: сам сервис отмены был покрыт и раньше, поломка жила в контроллере.
 */
class WebBookingCancelTest extends TestCase
{
    use RefreshDatabase;

    private function client(): User
    {
        return User::create([
            'first_name' => 'Иван',
            'last_name' => 'Петров',
            'phone' => '+79990000001',
            'role' => UserRole::Client,
            'password' => 'secret123',
            'offer_accepted_at' => now(),
        ]);
    }

    private function bookedSession(User $user): ClassSession
    {
        Subscription::create([
            'user_id' => $user->id,
            'type' => SubscriptionType::Group,
            'sessions_total' => 4,
            'sessions_used' => 0,
            'purchased_at' => now(),
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
        ]);

        return ClassSession::create([
            'topic' => 'Хатха-йога',
            'starts_at' => now()->addDays(3)->setTime(10, 0),
            'type' => SubscriptionType::Group,
            'capacity' => 6,
            'status' => ClassSessionStatus::Scheduled,
        ]);
    }

    public function test_client_cancels_booking_from_the_site(): void
    {
        $user = $this->client();
        $session = $this->bookedSession($user);
        $booking = app(BookingService::class)->book($user, $session);

        $response = $this->actingAs($user)->post(route('bookings.cancel', $booking));

        $response->assertRedirect(route('account'));
        $response->assertSessionHas('status');
        $response->assertSessionHasNoErrors();

        $this->assertSame(BookingStatus::CancelledByClient, $booking->fresh()->status);
    }

    public function test_client_cannot_cancel_someone_elses_booking(): void
    {
        $owner = $this->client();
        $session = $this->bookedSession($owner);
        $booking = app(BookingService::class)->book($owner, $session);

        // Оферта принята: без неё сработает EnsureOfferAccepted и до проверки
        // владельца брони дело не дойдёт — вместо 403 будет переадресация.
        $stranger = User::create([
            'first_name' => 'Пётр',
            'last_name' => 'Сидоров',
            'phone' => '+79990000002',
            'role' => UserRole::Client,
            'password' => 'secret123',
            'offer_accepted_at' => now(),
        ]);

        $this->actingAs($stranger)
            ->post(route('bookings.cancel', $booking))
            ->assertForbidden();

        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
    }
}
