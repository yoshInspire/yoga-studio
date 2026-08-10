<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\ClassSessionStatus;
use App\Enums\SubscriptionType;
use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Subscription;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Перенос записи администратором (ADMIN_PLAN_2.md, фаза L).
 *
 * Операционная задача: клиент звонит и просит переставить. Здесь стережём
 * то, ради чего перенос идёт через `BookingService`, а не через правку строки:
 * занятие возвращается на прежний абонемент и списывается с нового, и это
 * одна транзакция.
 */
class AdminRescheduleApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-06-10 12:00:00', config('app.timezone')));
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    private function client(): User
    {
        return User::factory()->create(['role' => UserRole::Client]);
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

    private function classAt(string $at, SubscriptionType $type = SubscriptionType::Group, int $capacity = 6): ClassSession
    {
        return ClassSession::create([
            'topic' => 'Хатха-йога',
            'starts_at' => Carbon::parse($at, config('app.timezone')),
            'type' => $type,
            'capacity' => $capacity,
            'status' => ClassSessionStatus::Scheduled,
        ]);
    }

    public function test_trainer_cannot_reschedule(): void
    {
        $client = $this->client();
        $this->subscription($client);
        $booking = app(BookingService::class)->bookForAdmin($client, $this->classAt('2026-06-12 10:00'));

        $this->actingAs(User::factory()->create(['role' => UserRole::Trainer]), 'sanctum')
            ->postJson('/api/v1/admin/bookings/'.$booking->id.'/reschedule', [
                'class_session_id' => $this->classAt('2026-06-13 10:00')->id,
            ])
            ->assertForbidden();
    }

    public function test_options_list_upcoming_sessions_with_free_places(): void
    {
        $client = $this->client();
        $this->subscription($client);
        $from = $this->classAt('2026-06-12 10:00');
        $booking = app(BookingService::class)->bookForAdmin($client, $from);

        $free = $this->classAt('2026-06-13 10:00');
        // Занятие без мест в список не попадает: перенести туда нельзя.
        $full = $this->classAt('2026-06-14 10:00', capacity: 1);
        $other = $this->client();
        $this->subscription($other);
        app(BookingService::class)->bookForAdmin($other, $full);
        // Прошедшее занятие тоже ни при чём.
        $this->classAt('2026-06-01 10:00');

        $payload = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/bookings/'.$booking->id.'/reschedule')
            ->assertOk()
            ->json();

        $ids = array_column($payload['sessions'], 'id');
        $this->assertContains($free->id, $ids);
        $this->assertNotContains($full->id, $ids);
        $this->assertNotContains($from->id, $ids);
        $this->assertSame($client->fullName(), $payload['booking']['client']);
    }

    public function test_reschedule_moves_the_booking_and_keeps_the_balance(): void
    {
        $client = $this->client();
        $subscription = $this->subscription($client);
        $booking = app(BookingService::class)->bookForAdmin($client, $this->classAt('2026-06-12 10:00'));

        $this->assertSame(1, $subscription->refresh()->sessions_used);

        $target = $this->classAt('2026-06-13 10:00');

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/bookings/'.$booking->id.'/reschedule', [
                'class_session_id' => $target->id,
            ])
            ->assertOk();

        $booking->refresh();
        $this->assertSame($target->id, $booking->class_session_id);
        $this->assertSame(BookingStatus::Confirmed, $booking->status);
        // Занятие вернулось на абонемент и списалось заново — остаток тот же.
        $this->assertSame(1, $subscription->refresh()->sessions_used);
    }

    /** Сроки отмены администратору не мешают: он договаривается лично. */
    public function test_reschedule_works_inside_the_client_deadline(): void
    {
        $client = $this->client();
        $this->subscription($client);
        // Занятие через час — клиенту перенос был бы уже закрыт.
        $booking = app(BookingService::class)->bookForAdmin($client, $this->classAt('2026-06-10 13:00'));

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/bookings/'.$booking->id.'/reschedule', [
                'class_session_id' => $this->classAt('2026-06-13 10:00')->id,
            ])
            ->assertOk();
    }

    public function test_subscription_of_another_type_is_refused(): void
    {
        $client = $this->client();
        $this->subscription($client);
        $booking = app(BookingService::class)->bookForAdmin($client, $this->classAt('2026-06-12 10:00'));

        $individual = $this->classAt('2026-06-13 10:00', SubscriptionType::Individual);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/bookings/'.$booking->id.'/reschedule', [
                'class_session_id' => $individual->id,
            ])
            ->assertStatus(422);

        $this->assertSame(BookingStatus::Confirmed, $booking->refresh()->status);
    }

    public function test_cancelled_booking_cannot_be_rescheduled(): void
    {
        $client = $this->client();
        $this->subscription($client);
        $booking = app(BookingService::class)->bookForAdmin($client, $this->classAt('2026-06-12 10:00'));
        app(BookingService::class)->cancelByAdmin($booking, 'по просьбе клиента');

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/bookings/'.$booking->id.'/reschedule', [
                'class_session_id' => $this->classAt('2026-06-13 10:00')->id,
            ])
            ->assertStatus(422);
    }

    public function test_moving_to_the_same_session_is_refused(): void
    {
        $client = $this->client();
        $this->subscription($client);
        $session = $this->classAt('2026-06-12 10:00');
        $booking = app(BookingService::class)->bookForAdmin($client, $session);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/bookings/'.$booking->id.'/reschedule', [
                'class_session_id' => $session->id,
            ])
            ->assertStatus(422);
    }

    /** Прежний абонемент кончился — списываем с указанного нового. */
    public function test_a_different_subscription_can_be_charged(): void
    {
        $client = $this->client();
        $first = $this->subscription($client);
        $booking = app(BookingService::class)->bookForAdmin($client, $this->classAt('2026-06-12 10:00'), $first);
        $second = $this->subscription($client);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/bookings/'.$booking->id.'/reschedule', [
                'class_session_id' => $this->classAt('2026-06-13 10:00')->id,
                'subscription_id' => $second->id,
            ])
            ->assertOk();

        $this->assertSame($second->id, $booking->refresh()->subscription_id);
        $this->assertSame(0, $first->refresh()->sessions_used);
        $this->assertSame(1, $second->refresh()->sessions_used);
    }

    public function test_someone_elses_subscription_is_refused(): void
    {
        $client = $this->client();
        $this->subscription($client);
        $booking = app(BookingService::class)->bookForAdmin($client, $this->classAt('2026-06-12 10:00'));

        $stranger = $this->client();
        $strangers = $this->subscription($stranger);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/bookings/'.$booking->id.'/reschedule', [
                'class_session_id' => $this->classAt('2026-06-13 10:00')->id,
                'subscription_id' => $strangers->id,
            ])
            ->assertStatus(422);
    }

    public function test_full_session_is_refused(): void
    {
        $client = $this->client();
        $this->subscription($client);
        $booking = app(BookingService::class)->bookForAdmin($client, $this->classAt('2026-06-12 10:00'));

        $full = $this->classAt('2026-06-13 10:00', capacity: 1);
        $other = $this->client();
        $this->subscription($other);
        app(BookingService::class)->bookForAdmin($other, $full);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/bookings/'.$booking->id.'/reschedule', [
                'class_session_id' => $full->id,
            ])
            ->assertStatus(422);

        $this->assertSame(1, Booking::query()->where('class_session_id', $full->id)->count());
    }
}
