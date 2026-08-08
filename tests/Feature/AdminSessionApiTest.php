<?php

namespace Tests\Feature;

use App\Enums\ClassSessionStatus;
use App\Enums\SubscriptionType;
use App\Enums\UserRole;
use App\Models\ClassSession;
use App\Models\Direction;
use App\Models\Subscription;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Расписание студии через API приложения: неделя, создание, правка, удаление.
 * Требования к полям те же, что у формы «Расписание» в веб-админке.
 */
class AdminSessionApiTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    private function classSession(array $overrides = []): ClassSession
    {
        return ClassSession::create(array_merge([
            'topic' => 'Хатха-йога',
            'starts_at' => now()->startOfDay()->addHours(14),
            'type' => SubscriptionType::Group,
            'capacity' => 6,
            'status' => ClassSessionStatus::Scheduled,
        ], $overrides));
    }

    public function test_week_carries_fields_for_the_edit_form(): void
    {
        // Фабрики у направлений нет, а lead/num в таблице NOT NULL.
        $direction = Direction::create([
            'title' => 'Хатха',
            'slug' => 'hatha',
            'num' => '01',
            'lead' => 'Мягкая практика для начинающих',
            'sort_order' => 1,
        ]);
        $session = $this->classSession([
            'direction_id' => $direction->id,
            'topic' => 'Мобильность плеч',
            'duration_minutes' => 75,
        ]);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/sessions')
            ->assertOk()
            ->assertJsonPath('sessions.0.id', $session->id)
            ->assertJsonPath('sessions.0.form.topic', 'Мобильность плеч')
            ->assertJsonPath('sessions.0.form.direction_id', $direction->id)
            ->assertJsonPath('sessions.0.form.duration_minutes', 75)
            ->assertJsonPath('sessions.0.form.time', $session->starts_at->format('H:i'));
    }

    public function test_past_weeks_are_reachable(): void
    {
        $this->classSession(['starts_at' => now()->subDays(3)->setTime(10, 0)]);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/sessions?offset=-1')
            ->assertOk()
            ->assertJsonPath('offset', -1)
            ->assertJsonCount(1, 'sessions');
    }

    public function test_week_can_be_filtered_by_type_and_status(): void
    {
        $this->classSession(['type' => SubscriptionType::Group]);
        $this->classSession(['type' => SubscriptionType::Individual, 'starts_at' => now()->startOfDay()->addHours(16)]);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/sessions?type=individual')
            ->assertOk()
            ->assertJsonCount(1, 'sessions')
            ->assertJsonPath('sessions.0.form.type', 'individual');

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/sessions?status=cancelled')
            ->assertOk()
            ->assertJsonCount(0, 'sessions');
    }

    public function test_meta_gives_defaults_per_type(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/meta')
            ->assertOk()
            ->assertJsonPath('types.1.value', 'individual')
            ->assertJsonPath('types.1.default_capacity', 1)
            ->assertJsonPath('topic_max_length', 120);
    }

    public function test_session_is_created_with_description_and_duration(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/sessions', [
                'topic' => 'Создание рельефа',
                'description' => 'Берём резинки',
                'date' => now()->addDay()->toDateString(),
                'time' => '19:30',
                'type' => 'group',
                'capacity' => 8,
                'duration_minutes' => 75,
            ])
            ->assertOk();

        $this->assertDatabaseHas('class_sessions', [
            'topic' => 'Создание рельефа',
            'description' => 'Берём резинки',
            'duration_minutes' => 75,
            'capacity' => 8,
        ]);
    }

    public function test_topic_is_required(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/sessions', [
                'date' => now()->toDateString(),
                'time' => '19:00',
                'type' => 'group',
                'capacity' => 6,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('topic');
    }

    public function test_malformed_time_is_rejected(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/sessions', [
                'topic' => 'Хатха',
                'date' => now()->toDateString(),
                'time' => '19-00',
                'type' => 'group',
                'capacity' => 6,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('time');
    }

    public function test_session_is_updated(): void
    {
        $session = $this->classSession();

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/v1/admin/sessions/'.$session->id, [
                'topic' => 'Мягкая практика',
                'date' => $session->starts_at->toDateString(),
                'time' => '20:15',
                'type' => 'group',
                'capacity' => 10,
                'duration_minutes' => 60,
            ])
            ->assertOk();

        $session->refresh();
        $this->assertSame('Мягкая практика', $session->topic);
        $this->assertSame('20:15', $session->starts_at->format('H:i'));
        $this->assertSame(10, $session->capacity);
    }

    public function test_update_cannot_cancel_the_session(): void
    {
        $session = $this->classSession();

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/v1/admin/sessions/'.$session->id, [
                'topic' => 'Хатха',
                'date' => $session->starts_at->toDateString(),
                'time' => '14:00',
                'type' => 'group',
                'capacity' => 6,
                'status' => 'cancelled',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertSame(ClassSessionStatus::Scheduled, $session->fresh()->status);
    }

    public function test_cancelled_session_can_be_returned_to_schedule(): void
    {
        $session = $this->classSession([
            'status' => ClassSessionStatus::Cancelled,
            'cancellation_reason' => 'Ошиблись датой',
        ]);

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/v1/admin/sessions/'.$session->id, [
                'topic' => 'Хатха',
                'date' => $session->starts_at->toDateString(),
                'time' => '14:00',
                'type' => 'group',
                'capacity' => 6,
                'status' => 'scheduled',
            ])
            ->assertOk();

        $session->refresh();
        $this->assertSame(ClassSessionStatus::Scheduled, $session->status);
        $this->assertNull($session->cancellation_reason);
    }

    public function test_empty_session_is_deleted(): void
    {
        $session = $this->classSession();

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson('/api/v1/admin/sessions/'.$session->id)
            ->assertOk();

        $this->assertDatabaseMissing('class_sessions', ['id' => $session->id]);
    }

    public function test_session_with_bookings_cannot_be_deleted(): void
    {
        $client = User::factory()->create(['role' => UserRole::Client]);
        Subscription::create([
            'user_id' => $client->id,
            'type' => SubscriptionType::Group,
            'sessions_total' => 4,
            'sessions_used' => 0,
            'purchased_at' => now(),
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
        ]);
        $session = $this->classSession();
        app(BookingService::class)->bookForAdmin($client, $session);

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson('/api/v1/admin/sessions/'.$session->id)
            ->assertStatus(422);

        $this->assertDatabaseHas('class_sessions', ['id' => $session->id]);
    }

    public function test_trainer_cannot_manage_schedule(): void
    {
        $session = $this->classSession();

        $this->actingAs(User::factory()->create(['role' => UserRole::Trainer]), 'sanctum')
            ->deleteJson('/api/v1/admin/sessions/'.$session->id)
            ->assertForbidden();
    }
}
