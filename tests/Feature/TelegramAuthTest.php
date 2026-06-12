<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\PhoneNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TelegramAuthTestHelper;
use Tests\TestCase;

class TelegramAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.bot_token' => 'test-bot-token',
            'services.telegram.bot_username' => 'TestYogaBot',
        ]);
    }

    public function test_existing_user_logs_in_via_telegram_callback(): void
    {
        $user = User::factory()->create([
            'telegram_id' => 123456789,
            'telegram_username' => 'ivan_petrov',
            'telegram_linked_at' => now(),
        ]);

        $payload = TelegramAuthTestHelper::signedPayload();

        $this->get(route('auth.telegram.callback', $payload))
            ->assertRedirect(route('account'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_unknown_telegram_user_is_redirected_to_registration(): void
    {
        $payload = TelegramAuthTestHelper::signedPayload([
            'id' => 987654321,
            'username' => 'new_user',
        ]);

        $response = $this->get(route('auth.telegram.callback', $payload));

        $response->assertRedirect(route('login'));
        $this->assertGuest();

        $response->assertSessionHas('auth_tab', 'register');
        $response->assertSessionHas('telegram_pending.id', 987654321);
        $response->assertSessionHas('telegram_pending.username', 'new_user');
    }

    public function test_registration_after_telegram_saves_telegram_fields(): void
    {
        $payload = TelegramAuthTestHelper::signedPayload([
            'id' => 555444333,
            'username' => 'anna_yoga',
            'first_name' => 'Анна',
            'last_name' => 'Смирнова',
        ]);

        $this->get(route('auth.telegram.callback', $payload));

        $response = $this->post(route('register'), [
            'first_name' => 'Анна',
            'last_name' => 'Смирнова',
            'birth_day' => 12,
            'birth_month' => 3,
            'birth_year' => 1990,
            'phone' => '+79991112233',
            'email' => 'anna@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'offer_accepted' => '1',
        ]);

        $response->assertRedirect(route('account'));

        $user = User::query()->where('phone', PhoneNormalizer::normalize('+79991112233'))->first();

        $this->assertNotNull($user);
        $this->assertSame(555444333, $user->telegram_id);
        $this->assertSame('anna_yoga', $user->telegram_username);
        $this->assertNotNull($user->telegram_linked_at);
        $this->assertSame('anna@example.com', $user->email);
    }

    public function test_telegram_registration_without_email_is_rejected(): void
    {
        $payload = TelegramAuthTestHelper::signedPayload([
            'id' => 555444334,
            'username' => 'no_email_user',
        ]);

        $this->get(route('auth.telegram.callback', $payload));

        $response = $this->post(route('register'), [
            'first_name' => 'Анна',
            'last_name' => 'Смирнова',
            'birth_day' => 12,
            'birth_month' => 3,
            'birth_year' => 1990,
            'phone' => '+79991112234',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'offer_accepted' => '1',
        ]);

        $response->assertSessionHasErrors('email', 'register');
        $this->assertGuest();
    }

    public function test_client_can_link_telegram_from_account(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::Client,
        ]);

        $payload = TelegramAuthTestHelper::signedPayload([
            'id' => 111222333,
            'username' => 'linked_user',
        ]);

        $this->actingAs($user)
            ->get(route('account.telegram.callback', $payload))
            ->assertRedirect(route('account'));

        $user->refresh();

        $this->assertSame(111222333, $user->telegram_id);
        $this->assertSame('linked_user', $user->telegram_username);
    }

    public function test_client_can_unlink_telegram(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::Client,
            'telegram_id' => 111222333,
            'telegram_username' => 'linked_user',
            'telegram_linked_at' => now(),
        ]);

        $this->actingAs($user)
            ->delete(route('account.telegram.unlink'))
            ->assertRedirect(route('account'));

        $user->refresh();

        $this->assertNull($user->telegram_id);
        $this->assertNull($user->telegram_username);
        $this->assertNull($user->telegram_linked_at);
    }

    public function test_telegram_cannot_be_linked_to_two_users(): void
    {
        User::factory()->create([
            'telegram_id' => 111222333,
            'telegram_username' => 'linked_user',
            'telegram_linked_at' => now(),
        ]);

        $anotherUser = User::factory()->create([
            'role' => UserRole::Client,
        ]);

        $payload = TelegramAuthTestHelper::signedPayload([
            'id' => 111222333,
            'username' => 'linked_user',
        ]);

        $this->actingAs($anotherUser)
            ->get(route('account.telegram.callback', $payload))
            ->assertRedirect(route('account'))
            ->assertSessionHasErrors('telegram');
    }

    public function test_invalid_telegram_hash_is_rejected(): void
    {
        $payload = TelegramAuthTestHelper::signedPayload();
        $payload['hash'] = 'invalid';

        $this->get(route('auth.telegram.callback', $payload))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('login');

        $this->assertGuest();
    }
}
