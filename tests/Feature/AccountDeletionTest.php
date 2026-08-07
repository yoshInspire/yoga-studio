<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\ClassSessionStatus;
use App\Enums\SubscriptionType;
use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Payment;
use App\Models\PushToken;
use App\Models\Subscription;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Удаление аккаунта клиентом — требование App Store 5.1.1(v) и Google Play.
 *
 * Главное, что проверяется: личные данные исчезают, а платежи остаются
 * (их оператор обязан хранить пять лет), и место в группе освобождается.
 */
class AccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function client(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'role' => UserRole::Client,
            'password' => 'secret123',
        ], $overrides));
    }

    private function classSession(): ClassSession
    {
        return ClassSession::create([
            'topic' => 'Хатха-йога',
            'starts_at' => now()->addDay()->setTime(10, 0),
            'type' => SubscriptionType::Group,
            'capacity' => 6,
            'status' => ClassSessionStatus::Scheduled,
        ]);
    }

    private function subscription(User $user): Subscription
    {
        return Subscription::create([
            'user_id' => $user->id,
            'type' => SubscriptionType::Group,
            'sessions_total' => 4,
            'sessions_used' => 0,
            'purchased_at' => now(),
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
        ]);
    }

    public function test_api_deletes_the_account_and_wipes_personal_data(): void
    {
        $client = $this->client([
            'first_name' => 'Мария',
            'email' => 'maria@example.com',
            'health_note' => 'Травма колена',
            'telegram_id' => 123456,
        ]);

        $response = $this->actingAs($client, 'sanctum')
            ->deleteJson('/api/v1/account', ['password' => 'secret123']);

        $response->assertOk();

        $client->refresh();

        $this->assertNotNull($client->anonymized_at);
        $this->assertNull($client->phone);
        $this->assertNull($client->email);
        $this->assertNull($client->health_note);
        $this->assertNull($client->telegram_id);
        $this->assertSame('Удалённый', $client->first_name);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_wrong_password_does_not_delete_anything(): void
    {
        $client = $this->client();

        $this->actingAs($client, 'sanctum')
            ->deleteJson('/api/v1/account', ['password' => 'not-my-password'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->assertNull($client->refresh()->anonymized_at);
        $this->assertNotNull($client->phone);
    }

    public function test_payments_survive_but_lose_the_person_behind_them(): void
    {
        $client = $this->client();

        $payment = Payment::create([
            'user_id' => $client->id,
            'product_key' => 'group_4',
            'amount' => 6000,
            'status' => 'succeeded',
            'starts_at' => now()->toDateString(),
            'description' => 'Абонемент на 4 занятия',
            'idempotence_key' => (string) \Illuminate\Support\Str::uuid(),
            'paid_at' => now(),
        ]);

        $this->actingAs($client, 'sanctum')
            ->deleteJson('/api/v1/account', ['password' => 'secret123'])
            ->assertOk();

        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'amount' => 6000]);
        $this->assertNull($payment->fresh()->user->phone);
    }

    public function test_future_booking_is_cancelled_and_the_seat_is_freed(): void
    {
        $client = $this->client();
        $this->subscription($client);
        $session = $this->classSession();

        app(BookingService::class)->book($client, $session);

        $this->actingAs($client, 'sanctum')
            ->deleteJson('/api/v1/account', ['password' => 'secret123'])
            ->assertOk();

        $booking = Booking::query()->where('user_id', $client->id)->firstOrFail();

        $this->assertSame(BookingStatus::CancelledByAdmin, $booking->status);
        $this->assertSame(0, $session->fresh()->bookings()->where('status', BookingStatus::Confirmed)->count());
    }

    public function test_chat_and_its_photos_are_removed(): void
    {
        Storage::fake(Message::DISK);

        $client = $this->client();
        $conversation = Conversation::create(['user_id' => $client->id]);

        Storage::disk(Message::DISK)->put('chat/1/photo.jpg', 'binary');
        Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $client->id,
            'body' => 'Здравствуйте',
            'attachment_path' => 'chat/1/photo.jpg',
        ]);

        $this->actingAs($client, 'sanctum')
            ->deleteJson('/api/v1/account', ['password' => 'secret123'])
            ->assertOk();

        $this->assertDatabaseCount('conversations', 0);
        $this->assertDatabaseCount('messages', 0);
        Storage::disk(Message::DISK)->assertMissing('chat/1/photo.jpg');
    }

    public function test_push_tokens_are_removed_so_notifications_stop(): void
    {
        $client = $this->client();

        PushToken::create([
            'user_id' => $client->id,
            'token' => 'ExponentPushToken[test]',
            'provider' => 'expo',
            'platform' => 'ios',
        ]);

        $this->actingAs($client, 'sanctum')
            ->deleteJson('/api/v1/account', ['password' => 'secret123'])
            ->assertOk();

        $this->assertDatabaseCount('push_tokens', 0);
    }

    public function test_active_subscription_is_closed(): void
    {
        $client = $this->client();
        $subscription = $this->subscription($client);

        $this->actingAs($client, 'sanctum')
            ->deleteJson('/api/v1/account', ['password' => 'secret123'])
            ->assertOk();

        $this->assertTrue($subscription->fresh()->ends_at->isToday());
    }

    public function test_website_form_deletes_the_account_and_logs_out(): void
    {
        $client = $this->client();

        $this->actingAs($client)
            ->delete(route('account.destroy'), ['password' => 'secret123', 'confirm' => '1'])
            ->assertRedirect(route('home'));

        $this->assertGuest();
        $this->assertNotNull($client->refresh()->anonymized_at);
    }

    public function test_website_form_requires_the_confirmation_checkbox(): void
    {
        $client = $this->client();

        $this->from(route('account'))
            ->actingAs($client)
            ->delete(route('account.destroy'), ['password' => 'secret123'])
            ->assertRedirect(route('account'));

        $this->assertNull($client->refresh()->anonymized_at);
    }

    public function test_deleted_account_cannot_log_in_again(): void
    {
        $client = $this->client(['phone' => '79990000009']);

        $this->actingAs($client, 'sanctum')
            ->deleteJson('/api/v1/account', ['password' => 'secret123'])
            ->assertOk();

        $this->postJson('/api/v1/login', ['phone' => '79990000009', 'password' => 'secret123'])
            ->assertStatus(422);
    }
}
