<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
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
 * Контроль посещений и запись клиента через API приложения.
 * Логика та же, что на странице «Посещения» в админке (см. VisitControlTest);
 * здесь проверяется, что её отдаёт и принимает API.
 */
class AdminVisitApiTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    private function client(array $overrides = []): User
    {
        return User::factory()->create(array_merge(['role' => UserRole::Client], $overrides));
    }

    private function subscription(User $user, SubscriptionType $type = SubscriptionType::Group): Subscription
    {
        return Subscription::create([
            'user_id' => $user->id,
            'type' => $type,
            'sessions_total' => 4,
            'sessions_used' => 0,
            'purchased_at' => now(),
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
        ]);
    }

    private function classSession(): ClassSession
    {
        return ClassSession::create([
            'topic' => 'Хатха-йога',
            'starts_at' => now()->startOfDay()->addHours(14),
            'type' => SubscriptionType::Group,
            'capacity' => 6,
            'status' => ClassSessionStatus::Scheduled,
        ]);
    }

    public function test_day_lists_sessions_with_roster_and_stats(): void
    {
        $client = $this->client(['first_name' => 'Иван', 'last_name' => 'Петров', 'patronymic' => null]);
        $this->subscription($client);
        $session = $this->classSession();
        $booking = app(BookingService::class)->bookForAdmin($client, $session);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/visits')
            ->assertOk()
            ->assertJsonPath('date', now()->toDateString())
            ->assertJsonPath('is_today', true)
            ->assertJsonPath('stats.sessions', 1)
            ->assertJsonPath('stats.pending', 1)
            ->assertJsonPath('sessions.0.id', $session->id)
            ->assertJsonPath('sessions.0.attendees.0.booking_id', $booking->id)
            ->assertJsonPath('sessions.0.attendees.0.name', 'Петров Иван')
            ->assertJsonPath('sessions.0.attendees.0.attendance', 'expected')
            ->assertJsonPath('sessions.0.attendees.0.sessions_remaining', 3);
    }

    public function test_day_accepts_explicit_date(): void
    {
        $tomorrow = now()->addDay()->toDateString();

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/visits?date='.$tomorrow)
            ->assertOk()
            ->assertJsonPath('date', $tomorrow)
            ->assertJsonPath('is_today', false)
            ->assertJsonPath('prev_date', now()->toDateString())
            ->assertJsonCount(0, 'sessions');
    }

    public function test_admin_marks_attendance(): void
    {
        $client = $this->client();
        $sub = $this->subscription($client);
        $booking = app(BookingService::class)->bookForAdmin($client, $this->classSession());

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/bookings/'.$booking->id.'/attended')
            ->assertOk();

        $this->assertSame(AttendanceStatus::Attended, $booking->fresh()->attendance_status);
        $this->assertSame(1, $sub->fresh()->sessions_used);
    }

    public function test_no_show_returns_session_to_subscription(): void
    {
        $client = $this->client();
        $sub = $this->subscription($client);
        $booking = app(BookingService::class)->bookForAdmin($client, $this->classSession());

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/bookings/'.$booking->id.'/no-show')
            ->assertOk();

        $this->assertSame(AttendanceStatus::NoShow, $booking->fresh()->attendance_status);
        $this->assertSame(0, $sub->fresh()->sessions_used);
    }

    public function test_cancel_releases_booking(): void
    {
        $client = $this->client();
        $sub = $this->subscription($client);
        $booking = app(BookingService::class)->bookForAdmin($client, $this->classSession());

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/bookings/'.$booking->id.'/cancel', ['reason' => 'Заболел'])
            ->assertOk();

        $booking->refresh();
        $this->assertSame(BookingStatus::CancelledByAdmin, $booking->status);
        $this->assertSame('Заболел', $booking->cancellation_reason);
        $this->assertSame(0, $sub->fresh()->sessions_used);
    }

    public function test_second_attendance_mark_on_cancelled_booking_is_422(): void
    {
        $client = $this->client();
        $this->subscription($client);
        $booking = app(BookingService::class)->bookForAdmin($client, $this->classSession());
        app(BookingService::class)->cancelByAdmin($booking);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/bookings/'.$booking->id.'/attended')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Запись отменена — отметить посещение нельзя.');
    }

    public function test_options_show_health_note_and_usable_subscriptions(): void
    {
        $client = $this->client(['health_note' => 'Грыжа L4-L5']);
        $sub = $this->subscription($client);
        $session = $this->classSession();

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/bookings/options?user_id='.$client->id.'&class_session_id='.$session->id)
            ->assertOk()
            ->assertJsonPath('client.health_note', 'Грыжа L4-L5')
            ->assertJsonPath('subscriptions.0.id', $sub->id)
            ->assertJsonPath('subscriptions.0.remaining', 4)
            ->assertJsonPath('note', null);
    }

    public function test_options_explain_missing_subscription_in_admin_wording(): void
    {
        $client = $this->client();
        $session = $this->classSession();

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/bookings/options?user_id='.$client->id.'&class_session_id='.$session->id)
            ->assertOk()
            ->assertJsonCount(0, 'subscriptions')
            ->assertJsonPath('note', 'Действующего абонемента нужного типа нет — сначала выдайте абонемент.');
    }

    public function test_admin_books_client_and_subscription_is_charged(): void
    {
        $client = $this->client();
        $sub = $this->subscription($client);
        $session = $this->classSession();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/bookings', [
                'user_id' => $client->id,
                'class_session_id' => $session->id,
            ])
            ->assertOk();

        $this->assertDatabaseHas('bookings', [
            'user_id' => $client->id,
            'class_session_id' => $session->id,
            'status' => BookingStatus::Confirmed->value,
            'subscription_id' => $sub->id,
        ]);
    }

    public function test_booking_without_suitable_subscription_is_422(): void
    {
        $client = $this->client();
        $session = $this->classSession();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/bookings', [
                'user_id' => $client->id,
                'class_session_id' => $session->id,
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_subscription_of_another_client_is_rejected(): void
    {
        $client = $this->client();
        $this->subscription($client);
        $other = $this->client();
        $otherSub = $this->subscription($other);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/bookings', [
                'user_id' => $client->id,
                'class_session_id' => $this->classSession()->id,
                'subscription_id' => $otherSub->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Выбранный абонемент принадлежит другому клиенту.');
    }

    public function test_trainer_cannot_be_booked_as_attendee(): void
    {
        $trainer = User::factory()->create(['role' => UserRole::Trainer]);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/bookings', [
                'user_id' => $trainer->id,
                'class_session_id' => $this->classSession()->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('user_id');
    }

    public function test_client_cannot_reach_visit_control(): void
    {
        $this->actingAs($this->client(), 'sanctum')
            ->getJson('/api/v1/admin/visits')
            ->assertForbidden();
    }
}
