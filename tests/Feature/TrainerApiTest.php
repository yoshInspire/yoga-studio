<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\ClassSessionStatus;
use App\Enums\SubscriptionType;
use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Direction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Неделя тренера в приложении.
 *
 * Данные считает `TrainerService::buildWeekSchedule()` — тот же, что рисует
 * страницу кабинета на сайте, поэтому здесь проверяется не расчёт, а контракт
 * API: кто имеет доступ, какие поля приходят и что тренер видит только свои
 * занятия и только разрешённые пометки о здоровье.
 */
class TrainerApiTest extends TestCase
{
    use RefreshDatabase;

    private function trainer(array $overrides = []): User
    {
        return User::factory()->create(array_merge(['role' => UserRole::Trainer], $overrides));
    }

    private function client(array $overrides = []): User
    {
        return User::factory()->create(array_merge(['role' => UserRole::Client], $overrides));
    }

    private function direction(): Direction
    {
        return Direction::create([
            'title' => 'Хатха',
            'slug' => 'hatha',
            'num' => '01',
            'lead' => 'Мягкая практика для начинающих',
            'sort_order' => 1,
        ]);
    }

    private function classSession(User $trainer, array $overrides = []): ClassSession
    {
        return ClassSession::create(array_merge([
            'trainer_id' => $trainer->id,
            'topic' => 'Мягкая практика',
            'starts_at' => now()->startOfDay()->addHours(10),
            'duration_minutes' => 90,
            'type' => SubscriptionType::Group,
            'capacity' => 6,
            'status' => ClassSessionStatus::Scheduled,
        ], $overrides));
    }

    private function book(User $client, ClassSession $session, BookingStatus $status = BookingStatus::Confirmed): Booking
    {
        return Booking::create([
            'user_id' => $client->id,
            'class_session_id' => $session->id,
            'status' => $status,
        ]);
    }

    /** @return array<string, mixed> Слот сегодняшнего дня из ответа. */
    private function todaySlot(array $payload): array
    {
        foreach ($payload['week'] as $day) {
            if ($day['is_today']) {
                return $day['slots'][0] ?? [];
            }
        }

        return [];
    }

    public function test_guest_cannot_open_trainer_week(): void
    {
        $this->getJson('/api/v1/trainer')->assertUnauthorized();
    }

    public function test_client_cannot_open_trainer_week(): void
    {
        $this->actingAs($this->client(), 'sanctum')
            ->getJson('/api/v1/trainer')
            ->assertForbidden();
    }

    public function test_admin_cannot_open_trainer_week(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]), 'sanctum')
            ->getJson('/api/v1/trainer')
            ->assertForbidden();
    }

    public function test_week_has_seven_days_with_labels_and_iso_date(): void
    {
        $payload = $this->actingAs($this->trainer(), 'sanctum')
            ->getJson('/api/v1/trainer')
            ->assertOk()
            ->assertJsonCount(7, 'week')
            ->json();

        $today = collect($payload['week'])->firstWhere('is_today', true);

        $this->assertNotNull($today, 'Текущая неделя обязана содержать сегодняшний день.');
        $this->assertSame(now()->toDateString(), $today['date_iso']);
        $this->assertNotEmpty($payload['week_label']);
    }

    public function test_slot_carries_fields_the_app_draws_card_from(): void
    {
        $trainer = $this->trainer();
        $session = $this->classSession($trainer, ['direction_id' => $this->direction()->id]);

        $payload = $this->actingAs($trainer, 'sanctum')->getJson('/api/v1/trainer')->assertOk()->json();
        $slot = $this->todaySlot($payload);

        $this->assertSame($session->id, $slot['id']);
        $this->assertSame('10:00', $slot['time']);
        $this->assertSame('10:00–11:30', $slot['time_range']);
        $this->assertSame('Хатха', $slot['direction']);
        // Цвет и иконку приложение подбирает по слагу: названия студия правит.
        $this->assertSame('hatha', $slot['direction_slug']);
        $this->assertSame('Мягкая практика', $slot['topic']);
        $this->assertSame('group', $slot['type']);
        $this->assertSame(6, $slot['total']);
        $this->assertSame('open', $slot['status']);
    }

    public function test_slot_without_direction_keeps_null_slug(): void
    {
        $trainer = $this->trainer();
        $this->classSession($trainer, ['type' => SubscriptionType::SpecialEvent]);

        $payload = $this->actingAs($trainer, 'sanctum')->getJson('/api/v1/trainer')->assertOk()->json();
        $slot = $this->todaySlot($payload);

        $this->assertNull($slot['direction']);
        $this->assertNull($slot['direction_slug']);
        $this->assertSame('event', $slot['type']);
    }

    public function test_roster_lists_confirmed_guests_with_initials(): void
    {
        $trainer = $this->trainer();
        $session = $this->classSession($trainer);
        $guest = $this->client(['first_name' => 'Иван', 'last_name' => 'Петров', 'patronymic' => null]);
        $booking = $this->book($guest, $session);

        $payload = $this->actingAs($trainer, 'sanctum')->getJson('/api/v1/trainer')->assertOk()->json();
        $slot = $this->todaySlot($payload);

        $this->assertSame(1, $slot['taken']);
        $this->assertCount(1, $slot['attendees']);
        $this->assertSame($booking->id, $slot['attendees'][0]['booking_id']);
        $this->assertSame('Иван Петров', $slot['attendees'][0]['name']);
        $this->assertSame('ИП', $slot['attendees'][0]['initials']);
        $this->assertNull($slot['attendees'][0]['avatar']);
    }

    public function test_roster_skips_cancelled_bookings(): void
    {
        $trainer = $this->trainer();
        $session = $this->classSession($trainer);
        $this->book($this->client(), $session, BookingStatus::CancelledByClient);

        $payload = $this->actingAs($trainer, 'sanctum')->getJson('/api/v1/trainer')->assertOk()->json();
        $slot = $this->todaySlot($payload);

        $this->assertSame(0, $slot['taken']);
        $this->assertSame([], $slot['attendees']);
    }

    public function test_health_note_is_shown_only_when_client_allowed_it(): void
    {
        $trainer = $this->trainer();
        $session = $this->classSession($trainer);

        $open = $this->client([
            'first_name' => 'Анна',
            'health_note' => 'Травма колена',
            'health_note_visible_to_trainer' => true,
        ]);
        $closed = $this->client([
            'first_name' => 'Мария',
            'health_note' => 'Личное',
            'health_note_visible_to_trainer' => false,
        ]);

        $this->book($open, $session);
        $this->book($closed, $session);

        $payload = $this->actingAs($trainer, 'sanctum')->getJson('/api/v1/trainer')->assertOk()->json();
        $notes = collect($this->todaySlot($payload)['attendees'])->pluck('health_note', 'name');

        $this->assertSame('Травма колена', $notes->first(fn ($note, $name) => str_contains($name, 'Анна')));
        $this->assertNull($notes->first(fn ($note, $name) => str_contains($name, 'Мария')));
    }

    public function test_trainer_sees_only_own_sessions(): void
    {
        $trainer = $this->trainer();
        $other = $this->trainer();
        $this->classSession($other, ['topic' => 'Чужое занятие']);

        $payload = $this->actingAs($trainer, 'sanctum')->getJson('/api/v1/trainer')->assertOk()->json();
        $slots = collect($payload['week'])->flatMap(fn (array $day) => $day['slots']);

        $this->assertCount(0, $slots);
    }

    public function test_cancelled_session_carries_status_and_reason(): void
    {
        $trainer = $this->trainer();
        $this->classSession($trainer, [
            'status' => ClassSessionStatus::Cancelled,
            'cancellation_reason' => 'Не набралась группа',
        ]);

        $payload = $this->actingAs($trainer, 'sanctum')->getJson('/api/v1/trainer')->assertOk()->json();
        $slot = $this->todaySlot($payload);

        $this->assertSame('cancelled', $slot['status']);
        $this->assertSame('Не набралась группа', $slot['reason']);
    }

    public function test_week_parameter_moves_the_window(): void
    {
        $trainer = $this->trainer();
        $nextWeek = now()->startOfWeek()->addWeek();
        $this->classSession($trainer, ['starts_at' => $nextWeek->copy()->addHours(10)]);

        // Текущая неделя пуста…
        $current = $this->actingAs($trainer, 'sanctum')->getJson('/api/v1/trainer')->assertOk()->json();
        $this->assertCount(0, collect($current['week'])->flatMap(fn (array $day) => $day['slots']));

        // …а по ссылке «вперёд» занятие находится.
        $ahead = $this->actingAs($trainer, 'sanctum')
            ->getJson('/api/v1/trainer?week='.$current['next_week'])
            ->assertOk()
            ->json();

        $this->assertCount(1, collect($ahead['week'])->flatMap(fn (array $day) => $day['slots']));
    }
}
