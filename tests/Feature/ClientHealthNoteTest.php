<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientHealthNoteTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_can_store_health_note(): void
    {
        $client = User::factory()->create([
            'role' => UserRole::Client,
            'health_note' => 'Гипертония, не давать перевёрнутые асаны.',
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $client->id,
            'health_note' => 'Гипертония, не давать перевёрнутые асаны.',
        ]);
    }

    public function test_health_note_is_not_exposed_on_public_account_page(): void
    {
        $client = User::factory()->create([
            'role' => UserRole::Client,
            'health_note' => 'Секретное примечание администратора',
            'password' => 'secret123',
        ]);

        $this->actingAs($client)
            ->get(route('account'))
            ->assertOk()
            ->assertDontSee('Секретное примечание администратора');
    }
}
