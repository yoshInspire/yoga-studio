<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\Asanas;
use App\Models\Asana;
use App\Models\AsanaFolder;
use App\Models\AsanaProgram;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AsanasPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::create([
            'first_name' => 'Ирина',
            'last_name' => 'Коленцева',
            'phone' => '+79990000099',
            'email' => 'admin@example.com',
            'role' => UserRole::Admin,
            'password' => 'secret123',
        ]));
    }

    private function asana(string $name = 'Тадасана'): Asana
    {
        return Asana::create([
            'name' => $name,
            'category' => 'Асаны стоя',
            'image_path' => 'images/asanas/library/asany-stoya/'.md5($name).'.png',
        ]);
    }

    public function test_page_opens_on_the_folder_list(): void
    {
        Livewire::test(Asanas::class)
            ->assertOk()
            ->assertSet('mode', 'folders');
    }

    public function test_folder_and_program_are_created_from_the_page(): void
    {
        Livewire::test(Asanas::class)
            ->set('newFolderName', 'Растяжка')
            ->call('createFolder')
            ->assertSet('newFolderName', '');

        $folder = AsanaFolder::firstWhere('name', 'Растяжка');
        $this->assertNotNull($folder);

        Livewire::test(Asanas::class)
            ->call('openFolder', $folder->id)
            ->set('newProgramTitle', 'Шпагаты')
            ->call('createProgram')
            ->assertSet('mode', 'program');

        $program = AsanaProgram::firstWhere('title', 'Шпагаты');
        $this->assertNotNull($program);
        $this->assertSame($folder->id, $program->folder_id);
    }

    public function test_blank_names_are_ignored(): void
    {
        Livewire::test(Asanas::class)
            ->set('newFolderName', '   ')
            ->call('createFolder')
            ->set('newProgramTitle', '')
            ->call('createProgram');

        $this->assertSame(0, AsanaFolder::count());
        $this->assertSame(0, AsanaProgram::count());
    }

    public function test_pose_is_added_to_the_open_program(): void
    {
        $program = AsanaProgram::create(['title' => 'Вечер']);
        $asana = $this->asana();

        Livewire::test(Asanas::class)
            ->call('openProgram', $program->id)
            ->call('addAsana', $asana->id);

        $this->assertSame(1, $program->items()->count());
        $this->assertSame($asana->id, $program->items()->first()->asana_id);
    }

    public function test_library_filters_by_category_and_search(): void
    {
        $this->asana('Тадасана');
        Asana::create([
            'name' => 'Бхуджангасана',
            'category' => 'Прогибы',
            'image_path' => 'images/asanas/library/progiby/b.png',
        ]);

        $program = AsanaProgram::create(['title' => 'Вечер']);

        $component = Livewire::test(Asanas::class)
            ->call('openProgram', $program->id)
            ->call('openLibrary')
            ->assertSet('mode', 'library')
            ->assertSee('Тадасана')
            ->assertSee('Бхуджангасана');

        $component->set('categoryFilter', 'Прогибы')
            ->assertSee('Бхуджангасана')
            ->assertDontSee('Тадасана');

        $component->set('categoryFilter', null)
            ->set('search', 'Тадас')
            ->assertSee('Тадасана')
            ->assertDontSee('Бхуджангасана');
    }

    public function test_library_cannot_be_opened_without_a_program(): void
    {
        Livewire::test(Asanas::class)
            ->call('openLibrary')
            ->assertSet('mode', 'folders');
    }

    public function test_poses_of_another_program_are_not_touched(): void
    {
        $mine = AsanaProgram::create(['title' => 'Моя']);
        $other = AsanaProgram::create(['title' => 'Чужая']);
        $asana = $this->asana();

        $foreignItem = $other->items()->create([
            'asana_id' => $asana->id,
            'position' => 1,
        ]);

        Livewire::test(Asanas::class)
            ->call('openProgram', $mine->id)
            ->call('removeItem', $foreignItem->id);

        $this->assertNotNull(
            $foreignItem->fresh(),
            'Элемент другого занятия не должен удаляться.',
        );
    }

    public function test_drawing_mode_is_tracked(): void
    {
        $program = AsanaProgram::create(['title' => 'Вечер']);
        $item = $program->items()->create([
            'asana_id' => $this->asana()->id,
            'position' => 1,
        ]);

        Livewire::test(Asanas::class)
            ->call('openProgram', $program->id)
            ->call('startNewDrawing')
            ->assertSet('drawingMode', 'new')
            ->assertSet('drawingItemId', null)
            ->call('startItemDrawing', $item->id)
            ->assertSet('drawingMode', 'item')
            ->assertSet('drawingItemId', $item->id)
            ->call('stopDrawing')
            ->assertSet('drawingMode', null);
    }

    public function test_program_note_is_saved(): void
    {
        $program = AsanaProgram::create(['title' => 'Вечер']);

        Livewire::test(Asanas::class)
            ->call('openProgram', $program->id)
            ->call('saveProgramNote', 'Для новичков');

        $this->assertSame('Для новичков', $program->fresh()->note);
    }
}
