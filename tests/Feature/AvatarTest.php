<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\AvatarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AvatarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('Без GD фотографии не обрабатываются.');
        }
    }

    private function client(array $overrides = []): User
    {
        return User::factory()->create(array_merge(['role' => UserRole::Client], $overrides));
    }

    public function test_client_uploads_avatar_through_the_api(): void
    {
        $client = $this->client();

        $response = $this->actingAs($client, 'sanctum')
            ->post('/api/v1/account/avatar', ['photo' => UploadedFile::fake()->image('me.jpg', 1200, 900)]);

        $response->assertOk();
        $this->assertNotNull($response->json('user.avatar_url'));

        $client->refresh();
        $this->assertNotNull($client->avatar_path);
        Storage::disk('public')->assertExists($client->avatar_path);
    }

    public function test_uploaded_avatar_is_stored_as_a_square(): void
    {
        $client = $this->client();

        $this->actingAs($client, 'sanctum')
            ->post('/api/v1/account/avatar', ['photo' => UploadedFile::fake()->image('wide.jpg', 1600, 900)])
            ->assertOk();

        $client->refresh();
        $size = getimagesizefromstring(Storage::disk('public')->get($client->avatar_path));

        $this->assertSame($size[0], $size[1], 'Аватар должен храниться квадратом.');
        $this->assertLessThanOrEqual(AvatarService::SIZE, $size[0]);
    }

    public function test_new_avatar_replaces_the_previous_file(): void
    {
        $client = $this->client();

        $this->actingAs($client, 'sanctum')
            ->post('/api/v1/account/avatar', ['photo' => UploadedFile::fake()->image('first.jpg', 600, 600)]);

        $first = $client->refresh()->avatar_path;

        $this->actingAs($client, 'sanctum')
            ->post('/api/v1/account/avatar', ['photo' => UploadedFile::fake()->image('second.jpg', 600, 600)]);

        $second = $client->refresh()->avatar_path;

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
    }

    public function test_client_removes_avatar(): void
    {
        $client = $this->client();

        $this->actingAs($client, 'sanctum')
            ->post('/api/v1/account/avatar', ['photo' => UploadedFile::fake()->image('me.jpg', 600, 600)]);

        $path = $client->refresh()->avatar_path;

        $this->actingAs($client, 'sanctum')
            ->deleteJson('/api/v1/account/avatar')
            ->assertOk()
            ->assertJsonPath('user.avatar_url', null);

        $this->assertNull($client->refresh()->avatar_path);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_non_image_file_is_rejected(): void
    {
        $this->actingAs($this->client(), 'sanctum')
            ->post('/api/v1/account/avatar', [
                'photo' => UploadedFile::fake()->create('practice.pdf', 40, 'application/pdf'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('photo')
            ->assertJsonPath('errors.photo.0', 'Такой формат не поддерживается. Подойдут JPEG, PNG или WEBP.');
    }

    public function test_guest_cannot_upload(): void
    {
        $this->postJson('/api/v1/account/avatar', [])->assertStatus(401);
    }

    public function test_trainer_can_upload_too(): void
    {
        $trainer = User::factory()->create(['role' => UserRole::Trainer]);

        $this->actingAs($trainer, 'sanctum')
            ->post('/api/v1/account/avatar', ['photo' => UploadedFile::fake()->image('coach.jpg', 800, 800)])
            ->assertOk();

        $this->assertNotNull($trainer->refresh()->avatar_path);
    }

    public function test_avatar_url_is_returned_by_me(): void
    {
        $client = $this->client();

        $this->actingAs($client, 'sanctum')
            ->post('/api/v1/account/avatar', ['photo' => UploadedFile::fake()->image('me.jpg', 600, 600)]);

        $this->actingAs($client, 'sanctum')
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('user.avatar_url', $client->refresh()->avatarUrl());
    }

    /* ------------------------------ Сайт ------------------------------ */

    public function test_site_upload_and_removal(): void
    {
        $client = $this->client();

        $this->actingAs($client)
            ->from(route('account'))
            ->post(route('account.avatar.store'), ['photo' => UploadedFile::fake()->image('me.jpg', 900, 1200)])
            ->assertRedirect(route('account'))
            ->assertSessionHas('status');

        $path = $client->refresh()->avatar_path;
        $this->assertNotNull($path);

        $this->actingAs($client)
            ->from(route('account'))
            ->delete(route('account.avatar.destroy'))
            ->assertRedirect(route('account'));

        $this->assertNull($client->refresh()->avatar_path);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_account_page_shows_the_photo(): void
    {
        $client = $this->client();

        $this->actingAs($client)
            ->post(route('account.avatar.store'), ['photo' => UploadedFile::fake()->image('me.jpg', 600, 600)]);

        $this->actingAs($client)
            ->get(route('account'))
            ->assertOk()
            ->assertSee($client->refresh()->avatarUrl(), false)
            ->assertSee('Сменить фото');
    }
}
