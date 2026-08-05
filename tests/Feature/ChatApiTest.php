<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ChatApiTest extends TestCase
{
    use RefreshDatabase;

    private function client(array $overrides = []): User
    {
        return User::factory()->create(array_merge(['role' => UserRole::Client], $overrides));
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    public function test_client_sends_message_and_sees_it_in_the_thread(): void
    {
        $client = $this->client();

        $this->actingAs($client, 'sanctum')
            ->postJson('/api/v1/chat', ['body' => 'Здравствуйте, подойдёт ли хатха новичку?'])
            ->assertCreated()
            ->assertJsonPath('message.body', 'Здравствуйте, подойдёт ли хатха новичку?')
            ->assertJsonPath('message.mine', true)
            ->assertJsonPath('message.from_client', true);

        $this->actingAs($client, 'sanctum')
            ->getJson('/api/v1/chat')
            ->assertOk()
            ->assertJsonCount(1, 'messages');

        $this->assertDatabaseCount('conversations', 1);
    }

    public function test_empty_message_without_photo_is_rejected(): void
    {
        $this->actingAs($this->client(), 'sanctum')
            ->postJson('/api/v1/chat', ['body' => '   '])
            ->assertStatus(422)
            ->assertJsonValidationErrors('body');
    }

    public function test_admin_sees_conversation_and_can_answer(): void
    {
        $client = $this->client(['first_name' => 'Мария', 'last_name' => 'Иванова', 'patronymic' => null]);
        $admin = $this->admin();

        $this->actingAs($client, 'sanctum')->postJson('/api/v1/chat', ['body' => 'Добрый день!']);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/chats')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Иванова Мария')
            ->assertJsonPath('data.0.last_message', 'Добрый день!')
            ->assertJsonPath('data.0.unread', 1)
            ->assertJsonPath('unread_total', 1);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/chats/{$client->id}", ['body' => 'Здравствуйте! Да, подойдёт.'])
            ->assertCreated()
            ->assertJsonPath('message.mine', true)
            ->assertJsonPath('message.from_client', false);

        // Для клиента тот же ответ студии оказывается на чужой стороне ленты.
        $this->actingAs($client, 'sanctum')
            ->getJson('/api/v1/chat')
            ->assertOk()
            ->assertJsonPath('messages.1.mine', false)
            ->assertJsonPath('unread', 1);
    }

    public function test_read_marks_only_the_other_sides_messages(): void
    {
        $client = $this->client();
        $admin = $this->admin();

        $this->actingAs($client, 'sanctum')->postJson('/api/v1/chat', ['body' => 'Вопрос']);
        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/chats/{$client->id}", ['body' => 'Ответ']);

        // Клиент читает — прочитанным становится только сообщение студии.
        $this->actingAs($client, 'sanctum')->postJson('/api/v1/chat/read')->assertOk();

        $conversation = Conversation::query()->where('user_id', $client->id)->firstOrFail();

        $this->assertSame(0, $conversation->unreadFromStudio()->count());
        $this->assertSame(1, $conversation->unreadFromClient()->count(), 'Сообщение клиента студия ещё не читала');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/chat/unread')
            ->assertOk()
            ->assertJsonPath('count', 1);

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/chats/{$client->id}/read")->assertOk();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/chat/unread')
            ->assertOk()
            ->assertJsonPath('count', 0);
    }

    public function test_client_cannot_reach_another_clients_conversation(): void
    {
        $mine = $this->client();
        $stranger = $this->client();

        $this->actingAs($stranger, 'sanctum')->postJson('/api/v1/chat', ['body' => 'Секрет']);

        // Клиентского маршрута с чужим идентификатором просто нет: лента
        // всегда берётся по токену. А админский клиенту закрыт ролью.
        $this->actingAs($mine, 'sanctum')
            ->getJson("/api/v1/admin/chats/{$stranger->id}")
            ->assertForbidden();

        $this->actingAs($mine, 'sanctum')
            ->getJson('/api/v1/chat')
            ->assertOk()
            ->assertJsonCount(0, 'messages');
    }

    public function test_after_returns_only_newer_messages(): void
    {
        $client = $this->client();
        $admin = $this->admin();

        $this->actingAs($client, 'sanctum')->postJson('/api/v1/chat', ['body' => 'Первое']);
        $firstId = Message::query()->latest('id')->firstOrFail()->id;

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/chats/{$client->id}", ['body' => 'Второе']);

        $this->actingAs($client, 'sanctum')
            ->getJson("/api/v1/chat?after={$firstId}")
            ->assertOk()
            ->assertJsonCount(1, 'messages')
            ->assertJsonPath('messages.0.body', 'Второе');
    }

    public function test_photo_is_stored_privately_and_served_through_the_route(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('Нужно расширение GD.');
        }

        $client = $this->client();
        $admin = $this->admin();
        $stranger = $this->client();

        $response = $this->actingAs($client, 'sanctum')->post('/api/v1/chat', [
            'photo' => UploadedFile::fake()->image('pose.jpg', 2400, 1800),
        ]);

        $response->assertCreated();
        $photoUrl = $response->json('message.photo');
        $this->assertNotNull($photoUrl);

        $message = Message::query()->latest('id')->firstOrFail();

        // Уменьшено до разумного размера и лежит вне публичного диска.
        $this->assertSame(1600, $message->attachment_width);
        $this->assertSame(1200, $message->attachment_height);
        $this->assertStringStartsWith('chat/', $message->attachment_path);
        $this->assertTrue($message->attachmentExists());

        $path = parse_url($photoUrl, PHP_URL_PATH);

        $this->actingAs($client, 'sanctum')->get($path)->assertOk();
        $this->actingAs($admin, 'sanctum')->get($path)->assertOk();
        $this->actingAs($stranger, 'sanctum')->get($path)->assertForbidden();
    }

    public function test_read_through_lets_the_sender_see_the_second_tick(): void
    {
        $client = $this->client();
        $admin = $this->admin();

        $this->actingAs($client, 'sanctum')->postJson('/api/v1/chat', ['body' => 'Вопрос']);
        $sentId = Message::query()->latest('id')->firstOrFail()->id;

        // Пока студия не прочитала — отметки нет.
        $this->actingAs($client, 'sanctum')
            ->getJson("/api/v1/chat?after={$sentId}")
            ->assertOk()
            ->assertJsonPath('read_through', null);

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/chats/{$client->id}/read");

        // Опрос отдаёт «прочитано до», хотя новых сообщений нет: иначе
        // отправитель никогда бы не увидел вторую галочку на уже показанном.
        $this->actingAs($client, 'sanctum')
            ->getJson("/api/v1/chat?after={$sentId}")
            ->assertOk()
            ->assertJsonCount(0, 'messages')
            ->assertJsonPath('read_through', $sentId);
    }

    public function test_non_image_file_is_rejected_with_a_russian_message(): void
    {
        $this->actingAs($this->client(), 'sanctum')
            ->post('/api/v1/chat', ['photo' => UploadedFile::fake()->create('practice.pdf', 40, 'application/pdf')])
            ->assertStatus(422)
            ->assertJsonValidationErrors('photo')
            ->assertJsonPath('errors.photo.0', 'Такой формат не поддерживается. Подойдут JPEG, PNG, WEBP или HEIC.');
    }

    public function test_png_screenshot_is_accepted(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('Нужно расширение GD.');
        }

        $this->actingAs($this->client(), 'sanctum')
            ->post('/api/v1/chat', ['photo' => UploadedFile::fake()->image('screen.png', 1200, 900)])
            ->assertCreated();
    }

    public function test_admin_search_filters_conversations_by_client(): void
    {
        $maria = $this->client(['first_name' => 'Мария', 'last_name' => 'Иванова']);
        $oleg = $this->client(['first_name' => 'Олег', 'last_name' => 'Петров']);

        $this->actingAs($maria, 'sanctum')->postJson('/api/v1/chat', ['body' => 'раз']);
        $this->actingAs($oleg, 'sanctum')->postJson('/api/v1/chat', ['body' => 'два']);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/chats?q=Петров')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user_id', $oleg->id);
    }

    public function test_conversations_are_sorted_by_last_message(): void
    {
        $first = $this->client(['first_name' => 'Анна']);
        $second = $this->client(['first_name' => 'Борис']);

        $this->actingAs($first, 'sanctum')->postJson('/api/v1/chat', ['body' => 'раньше']);
        $this->travel(5)->minutes();
        $this->actingAs($second, 'sanctum')->postJson('/api/v1/chat', ['body' => 'позже']);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/chats')
            ->assertOk()
            ->assertJsonPath('data.0.user_id', $second->id)
            ->assertJsonPath('data.1.user_id', $first->id);
    }
}
