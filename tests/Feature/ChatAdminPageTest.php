<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\Chat;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Страница переписок в веб-админке. Бэкенд общий с приложением, поэтому
 * проверяем только то, что добавляет сама страница: выбор клиента,
 * отправку, поиск и отметку о прочтении.
 */
class ChatAdminPageTest extends TestCase
{
    use RefreshDatabase;

    private function client(array $overrides = []): User
    {
        return User::factory()->create(array_merge(['role' => UserRole::Client], $overrides));
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        return $admin;
    }

    public function test_page_opens_and_shows_conversations(): void
    {
        $client = $this->client(['first_name' => 'Мария', 'last_name' => 'Иванова', 'patronymic' => null]);
        $this->actingAs($client, 'sanctum')->postJson('/api/v1/chat', ['body' => 'Добрый день!']);

        $this->actingAsAdmin();

        Livewire::test(Chat::class)
            ->assertOk()
            ->assertSee('Иванова Мария')
            ->assertSee('Добрый день!');
    }

    public function test_opening_page_marks_clients_messages_as_read(): void
    {
        $client = $this->client();
        $this->actingAs($client, 'sanctum')->postJson('/api/v1/chat', ['body' => 'Вопрос']);

        $conversation = Conversation::query()->where('user_id', $client->id)->firstOrFail();
        $this->assertSame(1, $conversation->unreadFromClient()->count());

        $this->actingAsAdmin();
        Livewire::test(Chat::class)->assertOk();

        $this->assertSame(0, $conversation->unreadFromClient()->count());
    }

    public function test_admin_answers_from_the_page(): void
    {
        $client = $this->client();
        $this->actingAs($client, 'sanctum')->postJson('/api/v1/chat', ['body' => 'Вопрос']);

        $this->actingAsAdmin();

        Livewire::test(Chat::class)
            ->set('draft', 'Здравствуйте! Отвечаю.')
            ->call('send')
            ->assertOk()
            ->assertSet('draft', '');

        // Клиент видит ответ у себя, и он для него чужой.
        $this->actingAs($client, 'sanctum')
            ->getJson('/api/v1/chat')
            ->assertOk()
            ->assertJsonPath('messages.1.body', 'Здравствуйте! Отвечаю.')
            ->assertJsonPath('messages.1.mine', false);
    }

    public function test_search_narrows_the_list_and_keeps_a_valid_selection(): void
    {
        $maria = $this->client(['first_name' => 'Мария', 'last_name' => 'Иванова']);
        $oleg = $this->client(['first_name' => 'Олег', 'last_name' => 'Петров']);

        $this->actingAs($maria, 'sanctum')->postJson('/api/v1/chat', ['body' => 'раз']);
        $this->actingAs($oleg, 'sanctum')->postJson('/api/v1/chat', ['body' => 'два']);

        $this->actingAsAdmin();

        Livewire::test(Chat::class)
            ->set('search', 'Петров')
            ->assertOk()
            ->assertSee('Петров Олег')
            ->assertDontSee('Иванова Мария')
            // Выбранным остаётся тот, кто виден: иначе лента показывала бы
            // клиента, которого в списке уже нет.
            ->assertSet('clientId', $oleg->id);
    }

    public function test_empty_message_is_not_sent(): void
    {
        $client = $this->client();
        $this->actingAs($client, 'sanctum')->postJson('/api/v1/chat', ['body' => 'Вопрос']);

        $this->actingAsAdmin();

        Livewire::test(Chat::class)
            ->set('draft', '   ')
            ->call('send')
            ->assertOk();

        // Кроме исходного сообщения клиента ничего не появилось.
        $this->assertDatabaseCount('messages', 1);
    }
}
