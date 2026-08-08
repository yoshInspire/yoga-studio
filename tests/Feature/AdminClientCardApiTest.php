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
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Карточка клиента и абонементы через API приложения.
 * Поля и правила — как в UserResource и SubscriptionResource веб-админки.
 */
class AdminClientCardApiTest extends TestCase
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

    private function subscription(User $user, array $overrides = []): Subscription
    {
        return Subscription::create(array_merge([
            'user_id' => $user->id,
            'type' => SubscriptionType::Group,
            'sessions_total' => 8,
            'sessions_used' => 0,
            'sessions_per_day' => 1,
            'purchased_at' => now(),
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
        ], $overrides));
    }

    private function classSession(array $overrides = []): ClassSession
    {
        return ClassSession::create(array_merge([
            'topic' => 'Хатха-йога',
            'starts_at' => now()->addDay()->setTime(19, 0),
            'type' => SubscriptionType::Group,
            'capacity' => 6,
            'status' => ClassSessionStatus::Scheduled,
        ], $overrides));
    }

    public function test_card_shows_subscriptions_bookings_and_stats(): void
    {
        $client = $this->client(['first_name' => 'Мария', 'last_name' => 'Иванова', 'patronymic' => null]);
        $sub = $this->subscription($client);
        $booking = app(BookingService::class)->bookForAdmin($client, $this->classSession());

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/clients/'.$client->id)
            ->assertOk()
            ->assertJsonPath('client.name', 'Иванова Мария')
            ->assertJsonPath('stats.active_subscriptions', 1)
            ->assertJsonPath('stats.upcoming', 1)
            ->assertJsonPath('subscriptions.0.id', $sub->id)
            ->assertJsonPath('subscriptions.0.sessions_remaining', 7)
            ->assertJsonPath('subscriptions.0.sessions_reserved', 1)
            ->assertJsonPath('upcoming.0.id', $booking->id)
            ->assertJsonCount(0, 'past');
    }

    public function test_past_bookings_are_separated_from_upcoming(): void
    {
        $client = $this->client();
        // Абонемент должен начаться раньше занятия, иначе списание невозможно.
        $this->subscription($client, ['starts_at' => now()->subWeek()]);
        app(BookingService::class)->bookForAdmin($client, $this->classSession([
            'starts_at' => now()->subDays(2)->setTime(10, 0),
        ]));

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/clients/'.$client->id)
            ->assertOk()
            ->assertJsonCount(0, 'upcoming')
            ->assertJsonCount(1, 'past');
    }

    public function test_card_of_trainer_is_rejected(): void
    {
        $trainer = User::factory()->create(['role' => UserRole::Trainer]);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/clients/'.$trainer->id)
            ->assertStatus(422);
    }

    public function test_client_data_is_updated_with_health_note(): void
    {
        $client = $this->client();

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/v1/admin/clients/'.$client->id, [
                'first_name' => 'Мария',
                'last_name' => 'Петрова',
                'patronymic' => 'Ивановна',
                'phone' => '79001112233',
                'email' => 'maria@example.com',
                'birth_day' => 14,
                'birth_month' => 3,
                'health_note' => 'Грыжа L4-L5',
                'health_note_visible_to_trainer' => true,
            ])
            ->assertOk()
            ->assertJsonPath('client.health_note', 'Грыжа L4-L5')
            ->assertJsonPath('client.health_note_visible_to_trainer', true);

        $client->refresh();
        $this->assertSame('Петрова', $client->last_name);
        $this->assertSame('79001112233', $client->phone);
    }

    public function test_phone_stays_unique(): void
    {
        $this->client(['phone' => '79001112233']);
        $client = $this->client();

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/v1/admin/clients/'.$client->id, [
                'first_name' => 'Иван',
                'last_name' => 'Петров',
                'phone' => '79001112233',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');
    }

    public function test_temporary_password_is_sent(): void
    {
        Mail::fake();
        $client = $this->client(['email' => 'client@example.com']);
        $before = $client->password;

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/clients/'.$client->id.'/access')
            ->assertOk();

        $this->assertNotSame($before, $client->fresh()->password);
    }

    public function test_subscription_is_issued_with_note_and_double_per_day(): void
    {
        $client = $this->client();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/subscriptions', [
                'user_id' => $client->id,
                'type' => 'group',
                'sessions_total' => 8,
                'sessions_per_day' => 2,
                'starts_at' => now()->toDateString(),
                'validity_days' => 45,
                'admin_note' => 'Подарочный',
            ])
            ->assertOk();

        $sub = $client->subscriptions()->firstOrFail();
        $this->assertSame(2, $sub->sessions_per_day);
        $this->assertSame('Подарочный', $sub->admin_note);
        $this->assertSame(now()->addDays(45)->toDateString(), $sub->ends_at->toDateString());
    }

    public function test_subscription_is_updated(): void
    {
        $client = $this->client();
        $sub = $this->subscription($client);

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/v1/admin/subscriptions/'.$sub->id, [
                'type' => 'individual',
                'sessions_total' => 10,
                'sessions_used' => 3,
                'sessions_per_day' => 1,
                'purchased_at' => $sub->purchased_at->toDateString(),
                'starts_at' => $sub->starts_at->toDateString(),
                'ends_at' => now()->addMonths(2)->toDateString(),
                'admin_note' => 'Пересчитали вручную',
            ])
            ->assertOk();

        $sub->refresh();
        $this->assertSame(SubscriptionType::Individual, $sub->type);
        $this->assertSame(10, $sub->sessions_total);
        $this->assertSame(3, $sub->sessions_used);
    }

    public function test_used_cannot_exceed_total(): void
    {
        $sub = $this->subscription($this->client());

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/v1/admin/subscriptions/'.$sub->id, [
                'type' => 'group',
                'sessions_total' => 4,
                'sessions_used' => 5,
                'sessions_per_day' => 1,
                'purchased_at' => $sub->purchased_at->toDateString(),
                'starts_at' => $sub->starts_at->toDateString(),
                'ends_at' => $sub->ends_at->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('sessions_used');
    }

    public function test_end_date_before_start_is_rejected(): void
    {
        $sub = $this->subscription($this->client());

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/v1/admin/subscriptions/'.$sub->id, [
                'type' => 'group',
                'sessions_total' => 8,
                'sessions_used' => 0,
                'sessions_per_day' => 1,
                'purchased_at' => $sub->purchased_at->toDateString(),
                'starts_at' => now()->toDateString(),
                'ends_at' => now()->subDay()->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ends_at');
    }

    public function test_extension_appends_a_line_to_the_admin_note(): void
    {
        $sub = $this->subscription($this->client(), ['ends_at' => now()->addDays(5)]);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/subscriptions/'.$sub->id.'/extend', [
                'days' => 14,
                'note' => 'болела',
            ])
            ->assertOk();

        $sub->refresh();
        $this->assertSame(now()->addDays(19)->toDateString(), $sub->ends_at->toDateString());
        $this->assertStringContainsString('продление на 14 дней', (string) $sub->admin_note);
        $this->assertStringContainsString('болела', (string) $sub->admin_note);
    }

    public function test_sessions_can_be_added(): void
    {
        $sub = $this->subscription($this->client(), ['sessions_total' => 4, 'sessions_used' => 4]);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/subscriptions/'.$sub->id.'/sessions', ['count' => 2])
            ->assertOk();

        $this->assertSame(6, $sub->fresh()->sessions_total);
    }

    public function test_client_cannot_open_another_client_card(): void
    {
        $other = $this->client();

        $this->actingAs($this->client(), 'sanctum')
            ->getJson('/api/v1/admin/clients/'.$other->id)
            ->assertForbidden();
    }
}
