<?php

namespace Tests\Feature;

use App\Enums\ClassSessionStatus;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionType;
use App\Enums\UserRole;
use App\Models\ClassSession;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Реестры админки: оплаты, абонементы, записи — фильтры и страницы.
 */
class AdminRegistriesApiTest extends TestCase
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

    private function payment(User $user, PaymentStatus $status, int $amount = 5000): Payment
    {
        return Payment::create([
            'user_id' => $user->id,
            'amount' => $amount,
            'status' => $status,
            'description' => 'Абонемент 8 занятий',
            'product_key' => 'group_8',
            'starts_at' => now(),
            // Ключ идемпотентности в таблице NOT NULL: платежи заводит ЮKassa.
            'idempotence_key' => (string) \Illuminate\Support\Str::uuid(),
        ]);
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

    public function test_payments_are_paginated_with_meta(): void
    {
        $client = $this->client();
        for ($i = 0; $i < 35; $i++) {
            $this->payment($client, PaymentStatus::Succeeded);
        }

        $first = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/payments')
            ->assertOk()
            ->assertJsonCount(30, 'data')
            ->assertJsonPath('meta.total', 35)
            ->assertJsonPath('meta.has_more', true);

        $this->assertSame(1, $first->json('meta.page'));

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/payments?page=2')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.has_more', false);
    }

    public function test_payments_are_filtered_by_status_and_client(): void
    {
        // Отчество гасим явно: фабрика ставит его случайно, а имя сверяем строкой.
        $maria = $this->client(['first_name' => 'Мария', 'last_name' => 'Иванова', 'patronymic' => null]);
        $petr = $this->client(['first_name' => 'Пётр', 'last_name' => 'Сидоров', 'patronymic' => null]);
        $this->payment($maria, PaymentStatus::Succeeded);
        $this->payment($petr, PaymentStatus::Canceled);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/payments?status=canceled')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.client', 'Сидоров Пётр')
            ->assertJsonPath('data.0.status_label', 'Отменён');

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/payments?q=Иванова')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.client_id', $maria->id);
    }

    public function test_subscriptions_registry_filters_by_state(): void
    {
        $client = $this->client();
        $active = $this->subscription($client);
        $expired = $this->subscription($client, [
            'starts_at' => now()->subMonths(3),
            'ends_at' => now()->subMonth(),
        ]);
        $future = $this->subscription($client, [
            'starts_at' => now()->addWeek(),
            'ends_at' => now()->addMonths(2),
        ]);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/subscriptions?state=active')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $active->id)
            ->assertJsonPath('data.0.sessions_remaining', 8);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/subscriptions?state=expired')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $expired->id);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/subscriptions?state=future')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $future->id);
    }

    public function test_subscriptions_registry_filters_by_type(): void
    {
        $client = $this->client();
        $this->subscription($client);
        $indiv = $this->subscription($client, ['type' => SubscriptionType::Individual]);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/subscriptions?type=individual')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $indiv->id);
    }

    public function test_bookings_registry_lists_newest_first_without_period(): void
    {
        $client = $this->client();
        $this->subscription($client, ['starts_at' => now()->subMonth()]);
        $older = app(BookingService::class)->bookForAdmin($client, $this->classSession([
            'starts_at' => now()->subDays(3)->setTime(10, 0),
        ]));
        $newer = app(BookingService::class)->bookForAdmin($client, $this->classSession([
            'starts_at' => now()->addDays(3)->setTime(10, 0),
        ]));

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/bookings')
            ->assertOk()
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.1.id', $older->id)
            ->assertJsonPath('meta.total', 2);
    }

    public function test_bookings_registry_reads_forward_inside_a_period(): void
    {
        $client = $this->client();
        $this->subscription($client, ['starts_at' => now()->subMonth()]);
        $soon = app(BookingService::class)->bookForAdmin($client, $this->classSession([
            'starts_at' => now()->addDay()->setTime(10, 0),
        ]));
        app(BookingService::class)->bookForAdmin($client, $this->classSession([
            'starts_at' => now()->addDays(5)->setTime(10, 0),
        ]));

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/bookings?date_from='.now()->toDateString())
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $soon->id);
    }

    public function test_bookings_registry_filters_cancelled_and_type(): void
    {
        $client = $this->client();
        $this->subscription($client, ['starts_at' => now()->subMonth()]);
        $booking = app(BookingService::class)->bookForAdmin($client, $this->classSession());
        app(BookingService::class)->cancelByAdmin($booking, 'Заболела');

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/bookings?status=cancelled')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.confirmed', false);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/bookings?status=confirmed')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/bookings?type=individual')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_bookings_registry_searches_by_client(): void
    {
        $maria = $this->client(['first_name' => 'Мария', 'last_name' => 'Иванова']);
        $petr = $this->client(['first_name' => 'Пётр', 'last_name' => 'Сидоров']);
        $this->subscription($maria);
        $this->subscription($petr);
        $session = $this->classSession();
        app(BookingService::class)->bookForAdmin($maria, $session);
        app(BookingService::class)->bookForAdmin($petr, $session);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/bookings?q=Сидоров')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.client_id', $petr->id);
    }

    public function test_registries_are_closed_for_clients(): void
    {
        $client = $this->client();

        foreach (['payments', 'subscriptions', 'bookings'] as $registry) {
            $this->actingAs($client, 'sanctum')
                ->getJson('/api/v1/admin/'.$registry)
                ->assertForbidden();
        }
    }
}
