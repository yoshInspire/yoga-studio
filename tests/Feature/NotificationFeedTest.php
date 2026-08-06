<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ClientNotification;
use App\Models\PushToken;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Лента уведомлений в приложении и пуши.
 *
 * Главное, что здесь проверяется: notifyUser() — единственная точка, через
 * которую идут все уведомления клиенту, поэтому лента наполняется сама собой
 * для любого их вида, и провал внешнего канала её не ломает.
 */
class NotificationFeedTest extends TestCase
{
    use RefreshDatabase;

    private function client(array $overrides = []): User
    {
        return User::factory()->create(array_merge(['role' => UserRole::Client], $overrides));
    }

    public function test_notify_user_stores_notification_in_the_feed(): void
    {
        Mail::fake();
        $user = $this->client();

        app(NotificationService::class)->notifyUser(
            $user,
            'Занятие отменено',
            ['Здравствуйте, Ирина!', 'Занятие 7 августа отменено.'],
            type: 'session_cancelled',
            payload: ['session_id' => 42],
        );

        $this->assertDatabaseHas('client_notifications', [
            'user_id' => $user->id,
            'type' => 'session_cancelled',
            'title' => 'Занятие отменено',
            'read_at' => null,
        ]);

        $stored = ClientNotification::query()->firstOrFail();
        $this->assertSame("Здравствуйте, Ирина!\nЗанятие 7 августа отменено.", $stored->body);
        $this->assertSame(['session_id' => 42], $stored->payload);
    }

    public function test_feed_is_returned_newest_first_with_unread_count(): void
    {
        $user = $this->client();

        ClientNotification::query()->create([
            'user_id' => $user->id, 'type' => 'news', 'title' => 'Старое', 'body' => 'x',
            'read_at' => now(), 'created_at' => now()->subDay(),
        ]);
        ClientNotification::query()->create([
            'user_id' => $user->id, 'type' => 'reminder', 'title' => 'Новое', 'body' => 'y',
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/notifications');

        $response->assertOk()
            ->assertJsonPath('unread', 1)
            ->assertJsonPath('data.0.title', 'Новое')
            ->assertJsonPath('data.0.read', false)
            ->assertJsonPath('data.1.read', true);
    }

    public function test_client_sees_only_own_notifications(): void
    {
        $user = $this->client();
        $stranger = $this->client();

        ClientNotification::query()->create([
            'user_id' => $stranger->id, 'type' => 'news', 'title' => 'Чужое', 'body' => 'x',
        ]);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('unread', 0);
    }

    public function test_read_marks_all_or_one(): void
    {
        $user = $this->client();
        $first = ClientNotification::query()->create([
            'user_id' => $user->id, 'type' => 'news', 'title' => 'A', 'body' => 'x',
        ]);
        ClientNotification::query()->create([
            'user_id' => $user->id, 'type' => 'news', 'title' => 'B', 'body' => 'y',
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/notifications/read', ['id' => $first->id])
            ->assertOk()
            ->assertJsonPath('unread', 1);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/notifications/read')
            ->assertOk()
            ->assertJsonPath('unread', 0);
    }

    public function test_read_cannot_touch_someone_elses_notification(): void
    {
        $user = $this->client();
        $stranger = $this->client();
        $foreign = ClientNotification::query()->create([
            'user_id' => $stranger->id, 'type' => 'news', 'title' => 'Чужое', 'body' => 'x',
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/notifications/read', ['id' => $foreign->id])
            ->assertOk();

        $this->assertNull($foreign->fresh()->read_at);
    }

    public function test_push_token_registration_moves_device_between_accounts(): void
    {
        $first = $this->client();
        $second = $this->client();
        $token = 'ExponentPushToken[abcdef123456]';

        $this->actingAs($first, 'sanctum')
            ->postJson('/api/v1/push-tokens', ['token' => $token, 'platform' => 'ios'])
            ->assertOk();

        // На том же телефоне вошёл другой человек — пуши должны уйти ему,
        // а не продолжать приходить предыдущему владельцу.
        $this->actingAs($second, 'sanctum')
            ->postJson('/api/v1/push-tokens', ['token' => $token, 'platform' => 'ios'])
            ->assertOk();

        $this->assertSame(1, PushToken::query()->count());
        $this->assertSame($second->id, PushToken::query()->first()->user_id);
    }

    public function test_device_can_only_be_detached_by_its_owner(): void
    {
        $owner = $this->client();
        $stranger = $this->client();
        $token = 'ExponentPushToken[abcdef123456]';

        PushToken::query()->create(['user_id' => $owner->id, 'token' => $token, 'provider' => 'expo']);

        $this->actingAs($stranger, 'sanctum')
            ->deleteJson('/api/v1/push-tokens', ['token' => $token])
            ->assertOk();
        $this->assertSame(1, PushToken::query()->count());

        $this->actingAs($owner, 'sanctum')
            ->deleteJson('/api/v1/push-tokens', ['token' => $token])
            ->assertOk();
        $this->assertSame(0, PushToken::query()->count());
    }

    public function test_push_is_sent_to_registered_devices_without_the_greeting_line(): void
    {
        Mail::fake();
        config(['services.push.driver' => 'expo']);
        Http::fake([
            'exp.host/*' => Http::response(['data' => [['status' => 'ok']]]),
        ]);

        $user = $this->client();
        PushToken::query()->create([
            'user_id' => $user->id, 'token' => 'ExponentPushToken[abc]', 'provider' => 'expo',
        ]);

        app(NotificationService::class)->notifyUser(
            $user,
            'Абонемент заканчивается',
            ['Здравствуйте, Ирина!', 'Осталось 1 занятие.'],
            type: 'subscription_low',
        );

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->url() === 'https://exp.host/--/api/v2/push/send'
                && $body[0]['to'] === 'ExponentPushToken[abc]'
                && $body[0]['title'] === 'Абонемент заканчивается'
                // «Здравствуйте, Ирина!» в шторке — потраченная строка.
                && $body[0]['body'] === 'Осталось 1 занятие.'
                && $body[0]['data']['type'] === 'subscription_low';
        });
    }

    public function test_no_devices_means_no_network_call(): void
    {
        Mail::fake();
        config(['services.push.driver' => 'expo']);
        Http::fake();

        app(NotificationService::class)->notifyUser($this->client(), 'Тема', ['Текст']);

        Http::assertNothingSent();
    }

    public function test_dead_device_is_removed_after_expo_reports_it(): void
    {
        Mail::fake();
        config(['services.push.driver' => 'expo']);
        Http::fake([
            'exp.host/*' => Http::response(['data' => [
                ['status' => 'error', 'details' => ['error' => 'DeviceNotRegistered']],
            ]]),
        ]);

        $user = $this->client();
        PushToken::query()->create([
            'user_id' => $user->id, 'token' => 'ExponentPushToken[dead]', 'provider' => 'expo',
        ]);

        app(NotificationService::class)->notifyUser($user, 'Тема', ['Текст']);

        $this->assertSame(0, PushToken::query()->count());
    }

    public function test_broken_push_service_does_not_break_the_feed(): void
    {
        Mail::fake();
        config(['services.push.driver' => 'expo']);
        Http::fake(['exp.host/*' => Http::response('boom', 500)]);

        $user = $this->client();
        PushToken::query()->create([
            'user_id' => $user->id, 'token' => 'ExponentPushToken[abc]', 'provider' => 'expo',
        ]);

        $result = app(NotificationService::class)->notifyUser($user, 'Тема', ['Текст']);

        $this->assertTrue($result['stored']);
        $this->assertSame(0, $result['push']);
        $this->assertDatabaseCount('client_notifications', 1);
    }

    public function test_guest_cannot_read_the_feed(): void
    {
        $this->getJson('/api/v1/notifications')->assertUnauthorized();
        $this->postJson('/api/v1/push-tokens', ['token' => 'x'])->assertUnauthorized();
    }
}
