<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\Asanas;
use App\Models\Asana;
use App\Models\AsanaCategory;
use App\Models\AsanaProgram;
use App\Models\User;
use App\Services\AsanaProgramService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Студия заводит свои разделы библиотеки и правит их.
 *
 * Название раздела хранится и в справочнике, и у самих поз, поэтому главное,
 * что здесь проверяется, — эти два места не расходятся.
 */
class AsanaCategoryManagementTest extends TestCase
{
    use RefreshDatabase;

    private AsanaProgramService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(AsanaProgramService::class);

        $this->actingAs(User::create([
            'first_name' => 'Ирина',
            'last_name' => 'Коленцева',
            'phone' => '+79990000099',
            'email' => 'admin@example.com',
            'role' => UserRole::Admin,
            'password' => 'secret123',
        ]));
    }

    private function pose(string $name, ?string $category): Asana
    {
        return Asana::create([
            'name' => $name,
            'category' => $category,
            'image_path' => 'images/asanas/library/'.md5($name).'.png',
        ]);
    }

    public function test_existing_sections_were_carried_over_by_migration(): void
    {
        $names = AsanaCategory::query()->pluck('name');

        $this->assertContains('Асаны стоя', $names);
        $this->assertContains('Прогибы', $names);
    }

    public function test_own_section_can_be_created(): void
    {
        $category = $this->service->createCategory('  Скрутки  ');

        $this->assertNotNull($category);
        $this->assertSame('Скрутки', $category->name, 'Лишние пробелы должны убираться.');
        $this->assertContains('Скрутки', $this->service->libraryCategories());
    }

    public function test_duplicate_and_empty_names_are_rejected(): void
    {
        $this->service->createCategory('Скрутки');

        $this->assertNull($this->service->createCategory('Скрутки'));
        $this->assertNull($this->service->createCategory('   '));
    }

    /** Ключевое: при переименовании позы не должны потерять раздел. */
    public function test_renaming_keeps_poses_in_the_section(): void
    {
        $category = $this->service->createCategory('Скрутки');
        $pose = $this->pose('Ардха матсиендрасана', 'Скрутки');

        $this->assertTrue($this->service->renameCategory($category, 'Скрутки и наклоны'));

        $this->assertSame('Скрутки и наклоны', $category->fresh()->name);
        $this->assertSame('Скрутки и наклоны', $pose->fresh()->category);
    }

    public function test_renaming_to_a_taken_name_is_rejected(): void
    {
        $this->service->createCategory('Скрутки');
        $other = $this->service->createCategory('Балансы');
        $pose = $this->pose('Поза', 'Балансы');

        $this->assertFalse($this->service->renameCategory($other, 'Скрутки'));
        $this->assertSame('Балансы', $pose->fresh()->category, 'Позы не должны пострадать.');
    }

    public function test_deleting_keeps_poses_but_clears_their_section(): void
    {
        $category = $this->service->createCategory('Скрутки');
        $pose = $this->pose('Ардха матсиендрасана', 'Скрутки');

        $affected = $this->service->deleteCategory($category);

        $this->assertSame(1, $affected);
        $this->assertNotNull($pose->fresh(), 'Поза удаляться не должна.');
        $this->assertNull($pose->fresh()->category);
        $this->assertNotContains('Скрутки', $this->service->libraryCategories());
    }

    public function test_own_drawing_can_be_put_into_a_new_section(): void
    {
        $this->service->createCategory('Скрутки');

        $drawing = $this->pose('Своя поза', null);
        $drawing->update(['is_custom' => true]);

        $drawing = $this->service->setCustomAsanaCategory($drawing, 'Скрутки');

        $this->assertSame('Скрутки', $drawing->category);
    }

    public function test_new_empty_section_is_visible_as_a_filter(): void
    {
        $this->service->createCategory('Скрутки');
        $program = AsanaProgram::create(['title' => 'Вечер']);

        Livewire::test(Asanas::class)
            ->call('openProgram', $program->id)
            ->call('openLibrary')
            ->assertSee('Скрутки');
    }

    public function test_section_is_created_from_the_page(): void
    {
        $program = AsanaProgram::create(['title' => 'Вечер']);

        Livewire::test(Asanas::class)
            ->call('openProgram', $program->id)
            ->call('openLibrary')
            ->call('toggleCategoryManager')
            ->assertSet('managingCategories', true)
            ->set('newCategoryName', 'Скрутки')
            ->call('createCategory')
            ->assertSet('newCategoryName', '');

        $this->assertNotNull(AsanaCategory::query()->firstWhere('name', 'Скрутки'));
    }

    public function test_open_filter_follows_the_renamed_section(): void
    {
        $category = $this->service->createCategory('Скрутки');
        $program = AsanaProgram::create(['title' => 'Вечер']);

        Livewire::test(Asanas::class)
            ->call('openProgram', $program->id)
            ->call('openLibrary')
            ->set('categoryFilter', 'Скрутки')
            ->call('renameCategory', $category->id, 'Скрутки и наклоны')
            ->assertSet('categoryFilter', 'Скрутки и наклоны');
    }

    public function test_open_filter_resets_when_its_section_is_deleted(): void
    {
        $category = $this->service->createCategory('Скрутки');
        $program = AsanaProgram::create(['title' => 'Вечер']);

        Livewire::test(Asanas::class)
            ->call('openProgram', $program->id)
            ->call('openLibrary')
            ->set('categoryFilter', 'Скрутки')
            ->call('deleteCategory', $category->id)
            ->assertSet('categoryFilter', null);
    }

    public function test_section_shows_how_many_poses_it_holds(): void
    {
        $category = $this->service->createCategory('Скрутки');
        $this->pose('Первая', 'Скрутки');
        $this->pose('Вторая', 'Скрутки');
        $this->pose('Третья', null);

        $this->assertSame(2, $category->asanaCount());
    }
}
