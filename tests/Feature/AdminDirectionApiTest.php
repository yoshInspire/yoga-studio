<?php

namespace Tests\Feature;

use App\Enums\ClassSessionStatus;
use App\Enums\SubscriptionType;
use App\Enums\UserRole;
use App\Models\ClassSession;
use App\Models\Direction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Направления студии из приложения (ADMIN_PLAN_2.md, фаза K).
 *
 * Стережём то, что легко сломать незаметно: диск `public_web` (а не `public`,
 * как у новостей), неизменность slug, разбор текстов в массивы и запрет
 * удалять направление, на котором висят занятия.
 */
class AdminDirectionApiTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Йога для спины',
            'num' => '07',
            'tag' => 'Новинка',
            'lead' => 'Мягкая практика для тех, кто много сидит.',
            'body' => "Первый абзац.\n\nВторой абзац.",
            'benefits' => "Меньше зажимов\nЛучше осанка",
            'is_published' => true,
        ], $overrides);
    }

    private function direction(array $overrides = []): Direction
    {
        return Direction::create(array_merge([
            'slug' => 'hatha',
            'num' => '01',
            'sort_order' => 1,
            'title' => 'Хатха-йога',
            'lead' => 'Классика.',
            'is_published' => true,
        ], $overrides));
    }

    public function test_client_cannot_reach_directions(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Client]), 'sanctum')
            ->getJson('/api/v1/admin/directions')
            ->assertForbidden();
    }

    public function test_index_shows_hidden_directions_too(): void
    {
        $this->direction();
        $this->direction(['slug' => 'yin', 'title' => 'Инь-йога', 'sort_order' => 2, 'is_published' => false]);

        $data = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/directions')
            ->assertOk()
            ->json('data');

        // Публичный список отдаёт только опубликованные — админский оба.
        $this->assertCount(2, $data);
        $this->assertFalse($data[1]['is_published']);
    }

    public function test_store_builds_a_latin_slug_from_the_russian_title(): void
    {
        $data = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/directions', $this->payload())
            ->assertCreated()
            ->json('data');

        $this->assertSame('yoga-dlya-spiny', $data['slug']);
        $this->assertSame(1, $data['sort_order']);
    }

    public function test_store_does_not_collide_with_an_existing_slug(): void
    {
        $this->direction(['slug' => 'yoga-dlya-spiny', 'title' => 'Йога для спины']);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/directions', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.slug', 'yoga-dlya-spiny-2');
    }

    public function test_texts_are_split_into_arrays_and_glued_back(): void
    {
        $id = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/directions', $this->payload())
            ->assertCreated()
            ->json('data.id');

        $direction = Direction::query()->findOrFail($id);
        // В базе это массивы — их читают и сайт, и приложение клиента.
        $this->assertSame(['Первый абзац.', 'Второй абзац.'], $direction->body);
        $this->assertSame(['Меньше зажимов', 'Лучше осанка'], $direction->benefits);

        // А в форму возвращается тот же текст, что человек набрал.
        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/directions/'.$id)
            ->assertOk()
            ->assertJsonPath('data.body', "Первый абзац.\n\nВторой абзац.")
            ->assertJsonPath('data.benefits', "Меньше зажимов\nЛучше осанка");
    }

    public function test_update_keeps_the_slug(): void
    {
        $direction = $this->direction();

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/v1/admin/directions/'.$direction->id, $this->payload([
                'title' => 'Совсем другое название',
                // Даже если кто-то пришлёт slug, он не должен примениться:
                // на нём висят ссылки сайта и папка с фотографиями.
                'slug' => 'sovsem-drugoe',
            ]))
            ->assertOk()
            ->assertJsonPath('data.slug', 'hatha')
            ->assertJsonPath('data.title', 'Совсем другое название');
    }

    public function test_empty_texts_become_empty_arrays(): void
    {
        $direction = $this->direction();

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/v1/admin/directions/'.$direction->id, $this->payload([
                'body' => '',
                'benefits' => null,
                'tag' => '',
            ]))
            ->assertOk();

        $direction->refresh();
        $this->assertSame([], $direction->body);
        $this->assertSame([], $direction->benefits);
        $this->assertNull($direction->tag);
    }

    public function test_title_and_lead_are_required(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/directions', $this->payload(['title' => '', 'lead' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title', 'lead']);
    }

    public function test_cover_lands_on_the_public_web_disk_in_the_slug_folder(): void
    {
        Storage::fake('public_web');
        Storage::fake('public');

        $direction = $this->direction();

        $this->actingAs($this->admin(), 'sanctum')
            ->post('/api/v1/admin/directions/'.$direction->id.'/cover', [
                'photo' => UploadedFile::fake()->image('cover.jpg', 1200, 900),
            ])
            ->assertOk();

        $path = $direction->refresh()->cover_image_path;
        $this->assertStringStartsWith('images/directions/hatha/', $path);
        Storage::disk('public_web')->assertExists($path);
        // Не тот диск, что у новостей и снимков тренеров.
        Storage::disk('public')->assertMissing($path);
    }

    public function test_replacing_the_cover_removes_the_old_file(): void
    {
        Storage::fake('public_web');

        $direction = $this->direction();

        $this->actingAs($this->admin(), 'sanctum')
            ->post('/api/v1/admin/directions/'.$direction->id.'/cover', [
                'photo' => UploadedFile::fake()->image('one.jpg', 800, 600),
            ])->assertOk();

        $first = $direction->refresh()->cover_image_path;

        $this->actingAs($this->admin(), 'sanctum')
            ->post('/api/v1/admin/directions/'.$direction->id.'/cover', [
                'photo' => UploadedFile::fake()->image('two.jpg', 800, 600),
            ])->assertOk();

        $this->assertNotSame($first, $direction->refresh()->cover_image_path);
        Storage::disk('public_web')->assertMissing($first);
    }

    public function test_gallery_can_be_filled_reordered_and_trimmed(): void
    {
        Storage::fake('public_web');

        $direction = $this->direction();

        foreach (['a.jpg', 'b.jpg'] as $name) {
            $this->actingAs($this->admin(), 'sanctum')
                ->post('/api/v1/admin/directions/'.$direction->id.'/slides', [
                    'photo' => UploadedFile::fake()->image($name, 800, 600),
                ])->assertOk();
        }

        $before = $direction->refresh()->gallery_paths;
        $this->assertCount(2, $before);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/directions/'.$direction->id.'/slides/1/move', ['direction' => 'up'])
            ->assertOk();

        $this->assertSame([$before[1], $before[0]], $direction->refresh()->gallery_paths);

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson('/api/v1/admin/directions/'.$direction->id.'/slides/0')
            ->assertOk();

        $this->assertSame([$before[0]], $direction->refresh()->gallery_paths);
        // Снимок убран из карусели — и с диска тоже.
        Storage::disk('public_web')->assertMissing($before[1]);
    }

    public function test_moving_the_first_slide_up_is_refused(): void
    {
        Storage::fake('public_web');

        $direction = $this->direction();

        $this->actingAs($this->admin(), 'sanctum')
            ->post('/api/v1/admin/directions/'.$direction->id.'/slides', [
                'photo' => UploadedFile::fake()->image('a.jpg', 800, 600),
            ])->assertOk();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/directions/'.$direction->id.'/slides/0/move', ['direction' => 'up'])
            ->assertStatus(422);
    }

    public function test_move_swaps_neighbours(): void
    {
        $first = $this->direction(['sort_order' => 1]);
        $second = $this->direction(['slug' => 'yin', 'title' => 'Инь-йога', 'sort_order' => 2]);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/directions/'.$second->id.'/move', ['direction' => 'up'])
            ->assertOk();

        $this->assertSame(1, $second->refresh()->sort_order);
        $this->assertSame(2, $first->refresh()->sort_order);
    }

    public function test_direction_with_classes_cannot_be_deleted(): void
    {
        $direction = $this->direction();

        ClassSession::create([
            'direction_id' => $direction->id,
            'topic' => 'Утренняя',
            'starts_at' => now()->addDay(),
            'type' => SubscriptionType::Group,
            'capacity' => 6,
            'status' => ClassSessionStatus::Scheduled,
        ]);

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson('/api/v1/admin/directions/'.$direction->id)
            ->assertStatus(422);

        $this->assertNotNull(Direction::query()->find($direction->id));
    }

    public function test_direction_without_classes_is_deleted_with_its_photos(): void
    {
        Storage::fake('public_web');

        $direction = $this->direction();

        $this->actingAs($this->admin(), 'sanctum')
            ->post('/api/v1/admin/directions/'.$direction->id.'/cover', [
                'photo' => UploadedFile::fake()->image('cover.jpg', 800, 600),
            ])->assertOk();

        $cover = $direction->refresh()->cover_image_path;

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson('/api/v1/admin/directions/'.$direction->id)
            ->assertOk();

        $this->assertNull(Direction::query()->find($direction->id));
        Storage::disk('public_web')->assertMissing($cover);
    }
}
