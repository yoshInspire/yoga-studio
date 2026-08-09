<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Asana;
use App\Models\AsanaCategory;
use App\Models\AsanaFolder;
use App\Models\AsanaProgram;
use App\Models\AsanaProgramItem;
use App\Models\User;
use App\Services\AsanaProgramService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Асаны и программы из приложения (ADMIN_PLAN_2.md, фаза M).
 *
 * Проверяем не сервис — он уже покрыт `AsanaProgramTest` и работает в вебе, —
 * а то, что приложение получает через API: порядок поз, зарисовку поверх
 * библиотечной позы, защиту от удаления используемой позы и готовый лист
 * печати с вшитыми картинками.
 */
class AdminAsanaApiTest extends TestCase
{
    use RefreshDatabase;

    /** Файлы в custom/ на момент запуска — всё лишнее убираем за собой. */
    private array $customBefore = [];

    private string $libraryDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customBefore = $this->customFiles();

        $this->libraryDir = public_path('images/asanas/library/__test');
        File::ensureDirectoryExists($this->libraryDir);
        File::put($this->libraryDir.'/pose.png', $this->pngBinary());
    }

    protected function tearDown(): void
    {
        foreach (array_diff($this->customFiles(), $this->customBefore) as $path) {
            File::delete($path);
        }

        if (File::isDirectory($this->libraryDir)) {
            File::deleteDirectory($this->libraryDir);
        }

        parent::tearDown();
    }

    /** @return list<string> */
    private function customFiles(): array
    {
        $dir = public_path(AsanaProgramService::CUSTOM_DIR);

        return File::isDirectory($dir)
            ? array_map(fn ($f) => $f->getPathname(), File::files($dir))
            : [];
    }

    private function pngBinary(): string
    {
        return (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        );
    }

    private function pngDataUrl(): string
    {
        return 'data:image/png;base64,'.base64_encode($this->pngBinary());
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    private function libraryAsana(string $name = 'Тадасана', ?string $category = 'Асаны стоя'): Asana
    {
        return Asana::create([
            'name' => $name,
            'category' => $category,
            'image_path' => 'images/asanas/library/__test/pose.png',
        ]);
    }

    public function test_client_cannot_reach_asanas(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Client]), 'sanctum')
            ->getJson('/api/v1/admin/asanas')
            ->assertForbidden();
    }

    public function test_index_shows_folder_contents_and_counts_the_whole_subtree(): void
    {
        $root = AsanaFolder::create(['name' => 'Растяжка']);
        $nested = AsanaFolder::create(['parent_id' => $root->id, 'name' => 'Шпагаты']);

        AsanaProgram::create(['folder_id' => $root->id, 'title' => 'Разминка']);
        AsanaProgram::create(['folder_id' => $nested->id, 'title' => 'Продольный']);

        $payload = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/asanas')
            ->assertOk()
            ->json();

        $this->assertNull($payload['folder']);
        $this->assertSame([], $payload['programs']);

        $folder = $payload['folders'][0];
        $this->assertSame('Растяжка', $folder['name']);
        // Занятие лежит во вложенной папке, но в предупреждении об удалении
        // должно быть видно и его.
        $this->assertSame(2, $folder['programs_count']);
        $this->assertSame(1, $folder['folders_count']);

        $inside = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/asanas?folder='.$root->id)
            ->assertOk()
            ->json();

        $this->assertSame(['Растяжка'], array_column($inside['breadcrumbs'], 'name'));
        $this->assertSame(['Разминка'], array_column($inside['programs'], 'title'));
    }

    public function test_deleting_a_folder_keeps_programs_and_moves_them_to_the_root(): void
    {
        $folder = AsanaFolder::create(['name' => 'Утро']);
        $nested = AsanaFolder::create(['parent_id' => $folder->id, 'name' => 'Короткие']);
        $program = AsanaProgram::create(['folder_id' => $nested->id, 'title' => 'Пять минут']);

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson('/api/v1/admin/asanas/folders/'.$folder->id)
            ->assertOk()
            ->assertJsonPath('freed_programs', 1);

        $this->assertDatabaseMissing('asana_folders', ['id' => $nested->id]);
        // Занятие ценнее папки: оно всплывает в корень, а не удаляется.
        $this->assertNull($program->refresh()->folder_id);
    }

    public function test_program_keeps_the_order_of_poses(): void
    {
        $program = AsanaProgram::create(['title' => 'Практика']);
        $first = $this->libraryAsana('Тадасана');
        $second = $this->libraryAsana('Врикшасана');

        $admin = $this->admin();

        foreach ([$first, $second] as $asana) {
            $this->actingAs($admin, 'sanctum')
                ->postJson('/api/v1/admin/asanas/programs/'.$program->id.'/items', ['asana_id' => $asana->id])
                ->assertCreated();
        }

        $items = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/asanas/programs/'.$program->id)
            ->assertOk()
            ->json('items');

        $this->assertSame(['Тадасана', 'Врикшасана'], array_column($items, 'title'));

        $moved = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/asanas/items/'.$items[1]['id'].'/move', ['direction' => 'up'])
            ->assertOk()
            ->json('items');

        $this->assertSame(['Врикшасана', 'Тадасана'], array_column($moved, 'title'));

        $left = $this->actingAs($admin, 'sanctum')
            ->deleteJson('/api/v1/admin/asanas/items/'.$items[0]['id'])
            ->assertOk()
            ->json('items');

        $this->assertSame(['Врикшасана'], array_column($left, 'title'));
    }

    public function test_drawing_over_a_pose_does_not_touch_the_library(): void
    {
        $asana = $this->libraryAsana();
        $program = AsanaProgram::create(['title' => 'Практика']);
        $item = app(AsanaProgramService::class)->addAsana($program, $asana);

        $data = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/asanas/items/'.$item->id.'/drawing', ['image' => $this->pngDataUrl()])
            ->assertOk()
            ->json('data');

        $this->assertTrue($data['edited']);
        // Поза в библиотеке осталась прежней — правка легла на элемент занятия.
        $this->assertSame('images/asanas/library/__test/pose.png', $asana->refresh()->image_path);
        $this->assertStringContainsString('asanas/custom', (string) $item->refresh()->image_path);

        $reset = $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson('/api/v1/admin/asanas/items/'.$item->id.'/drawing')
            ->assertOk()
            ->json('data');

        $this->assertFalse($reset['edited']);
        $this->assertNull($item->refresh()->image_path);
    }

    public function test_drawing_must_be_a_png(): void
    {
        $program = AsanaProgram::create(['title' => 'Практика']);
        $item = app(AsanaProgramService::class)->addAsana($program, $this->libraryAsana());

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/asanas/items/'.$item->id.'/drawing', [
                'image' => 'data:image/jpeg;base64,'.base64_encode('нет'),
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Ожидается изображение PNG.');
    }

    public function test_duplicate_copies_the_sequence(): void
    {
        $program = AsanaProgram::create(['title' => 'Практика', 'note' => 'для новичков']);
        app(AsanaProgramService::class)->addAsana($program, $this->libraryAsana());

        $copy = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/asanas/programs/'.$program->id.'/duplicate')
            ->assertCreated()
            ->json('data');

        $this->assertSame('Практика (копия)', $copy['program']['title']);
        $this->assertCount(1, $copy['items']);
    }

    public function test_library_filters_by_section_and_by_own_drawings(): void
    {
        // Библиотека приезжает миграцией и полна поз, поэтому свои кладём
        // в отдельный раздел — иначе фильтр не отличить от seed-данных.
        $this->libraryAsana('Проверочная поза', 'Раздел для проверки');
        $this->libraryAsana('Другая поза', 'Иной раздел');

        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/asanas/library', [
                'image' => $this->pngDataUrl(),
                'name' => 'Моя поза',
            ])
            ->assertCreated();

        $standing = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/asanas/library?category='.urlencode('Раздел для проверки'))
            ->assertOk()
            ->json('data');

        $this->assertSame(['Проверочная поза'], array_column($standing, 'name'));

        $mine = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/asanas/library?category='.urlencode(Asana::CUSTOM_CATEGORY))
            ->assertOk()
            ->json('data');

        $this->assertSame(['Моя поза'], array_column($mine, 'name'));

        $found = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/asanas/library?q='.urlencode('Другая'))
            ->assertOk()
            ->json('data');

        $this->assertSame(['Другая поза'], array_column($found, 'name'));
    }

    public function test_new_drawing_can_go_straight_into_the_program(): void
    {
        $program = AsanaProgram::create(['title' => 'Практика']);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/asanas/library', [
                'image' => $this->pngDataUrl(),
                'name' => 'Своя поза',
                'program_id' => $program->id,
            ])
            ->assertCreated()
            ->assertJsonPath('added_to_program', true);

        $this->assertSame(1, $program->items()->count());
    }

    public function test_own_pose_used_in_a_program_is_not_deleted(): void
    {
        $service = app(AsanaProgramService::class);
        $asana = $service->storeCustomAsana($this->pngDataUrl(), 'Своя поза');
        $program = AsanaProgram::create(['title' => 'Практика']);
        $service->addAsana($program, $asana);

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson('/api/v1/admin/asanas/library/'.$asana->id)
            ->assertStatus(422)
            ->assertJsonPath('used_in', 1);

        $this->assertDatabaseHas('asanas', ['id' => $asana->id]);
    }

    public function test_library_pose_cannot_be_deleted_at_all(): void
    {
        $asana = $this->libraryAsana();

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson('/api/v1/admin/asanas/library/'.$asana->id)
            ->assertStatus(422);

        $this->assertDatabaseHas('asanas', ['id' => $asana->id]);
    }

    public function test_renaming_a_section_moves_the_poses_with_it(): void
    {
        // Разделы приезжают из миграции — заводить свой не нужно.
        $category = AsanaCategory::query()->firstOrCreate(['name' => 'Асаны стоя']);
        $asana = $this->libraryAsana('Тадасана', 'Асаны стоя');

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/v1/admin/asanas/categories/'.$category->id, ['name' => 'Стоя'])
            ->assertOk()
            ->assertJsonPath('name', 'Стоя');

        // Раздел хранится у позы строкой — без переноса она потеряла бы его.
        $this->assertSame('Стоя', $asana->refresh()->category);

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson('/api/v1/admin/asanas/categories/'.$category->id)
            ->assertOk();

        $this->assertNull($asana->refresh()->category);
        $this->assertDatabaseHas('asanas', ['id' => $asana->id]);
    }

    public function test_print_returns_a_sheet_with_the_pictures_inside(): void
    {
        $program = AsanaProgram::create(['title' => 'Утренняя практика', 'note' => 'мягко']);
        $item = app(AsanaProgramService::class)->addAsana($program, $this->libraryAsana());
        $item->update(['note' => '5 дыханий']);

        $payload = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/asanas/programs/'.$program->id.'/print')
            ->assertOk()
            ->json();

        $this->assertStringContainsString('Утренняя практика', $payload['html']);
        $this->assertStringContainsString('5 дыханий', $payload['html']);
        // Картинки вшиты, а не ссылками: печать не должна ждать сеть.
        $this->assertStringContainsString('data:image/png;base64,', $payload['html']);
        $this->assertStringNotContainsString('<img src="/images', $payload['html']);
        $this->assertSame(1, $payload['layout']['pages']);
    }

    public function test_print_of_an_empty_program_is_refused(): void
    {
        $program = AsanaProgram::create(['title' => 'Пусто']);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/asanas/programs/'.$program->id.'/print')
            ->assertStatus(422);
    }

    public function test_program_note_and_pose_caption_are_saved(): void
    {
        $program = AsanaProgram::create(['title' => 'Практика']);
        $item = app(AsanaProgramService::class)->addAsana($program, $this->libraryAsana());

        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/admin/asanas/programs/'.$program->id, [
                'title' => 'Практика для спины',
                'note' => 'без прогибов',
            ])
            ->assertOk()
            ->assertJsonPath('data.program.note', 'без прогибов');

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/admin/asanas/items/'.$item->id, ['note' => 'счёт 8'])
            ->assertOk()
            ->assertJsonPath('data.note', 'счёт 8');

        $this->assertSame('Практика для спины', $program->refresh()->title);
        $this->assertInstanceOf(AsanaProgramItem::class, $item->refresh());
    }
}
