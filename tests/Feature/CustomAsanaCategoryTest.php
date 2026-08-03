<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\Asanas;
use App\Models\Asana;
use App\Models\AsanaProgram;
use App\Models\User;
use App\Services\AsanaProgramService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Свои зарисовки должны попадать в разделы библиотеки («Асаны стоя» и прочие)
 * рядом с готовыми позами, а не оседать только в «Моих зарисовках».
 */
class CustomAsanaCategoryTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $created = [];

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

        // Готовые позы задают доступные разделы.
        Asana::create(['name' => 'Тадасана', 'category' => 'Асаны стоя', 'image_path' => 'images/asanas/library/a.png']);
        Asana::create(['name' => 'Ваджрасана', 'category' => 'Асаны сидя и лежа', 'image_path' => 'images/asanas/library/b.png']);
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $path) {
            if (File::exists(public_path($path))) {
                File::delete(public_path($path));
            }
        }

        parent::tearDown();
    }

    private function pngDataUrl(): string
    {
        return 'data:image/png;base64,'.base64_encode(base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        ));
    }

    private function drawing(?string $category = null): Asana
    {
        $asana = $this->service->storeCustomAsana($this->pngDataUrl(), 'Моя поза', $category);
        $this->created[] = $asana->image_path;

        return $asana;
    }

    public function test_library_categories_come_from_ready_poses(): void
    {
        // Библиотеку наполняет миграция, поэтому разделов больше, чем завели тут.
        $categories = $this->service->libraryCategories();

        $this->assertContains('Асаны стоя', $categories);
        $this->assertContains('Асаны сидя и лежа', $categories);
        $this->assertSame(array_values(array_unique($categories)), $categories, 'Разделы не должны повторяться.');
    }

    public function test_own_drawings_do_not_create_new_sections(): void
    {
        $before = $this->service->libraryCategories();
        $this->drawing();

        $this->assertSame($before, $this->service->libraryCategories());
    }

    public function test_drawing_can_be_saved_into_a_library_category(): void
    {
        $asana = $this->drawing('Асаны стоя');

        $this->assertTrue($asana->is_custom);
        $this->assertSame('Асаны стоя', $asana->category);
    }

    public function test_drawing_without_category_stays_in_own_section(): void
    {
        $this->assertNull($this->drawing()->category);
        $this->assertNull($this->drawing('')->category);
    }

    public function test_unknown_category_is_not_accepted(): void
    {
        $this->assertNull($this->drawing('Придуманный раздел')->category);
    }

    public function test_category_can_be_changed_later(): void
    {
        $asana = $this->drawing();

        $asana = $this->service->setCustomAsanaCategory($asana, 'Асаны сидя и лежа');
        $this->assertSame('Асаны сидя и лежа', $asana->category);

        $asana = $this->service->setCustomAsanaCategory($asana, '');
        $this->assertNull($asana->category, 'Должна возвращаться в «Мои зарисовки».');
    }

    public function test_ready_pose_category_cannot_be_changed(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->setCustomAsanaCategory(
            Asana::query()->where('name', 'Тадасана')->firstOrFail(),
            'Асаны сидя и лежа',
        );
    }

    /** Главное, ради чего всё делалось: своя поза видна рядом с готовыми. */
    public function test_drawing_shows_up_in_its_library_section(): void
    {
        $this->drawing('Асаны стоя');
        $program = AsanaProgram::create(['title' => 'Вечер']);

        Livewire::test(Asanas::class)
            ->call('openProgram', $program->id)
            ->call('openLibrary')
            ->set('categoryFilter', 'Асаны стоя')
            ->assertSee('Моя поза')
            ->assertSee('Тадасана')
            ->assertDontSee('Ваджрасана');
    }

    public function test_drawing_is_still_listed_among_own_drawings(): void
    {
        $this->drawing('Асаны стоя');
        $program = AsanaProgram::create(['title' => 'Вечер']);

        Livewire::test(Asanas::class)
            ->call('openProgram', $program->id)
            ->call('openLibrary')
            ->set('categoryFilter', Asana::CUSTOM_CATEGORY)
            ->assertSee('Моя поза')
            ->assertDontSee('Тадасана');
    }

    public function test_category_is_changed_from_the_page(): void
    {
        $asana = $this->drawing();
        $program = AsanaProgram::create(['title' => 'Вечер']);

        Livewire::test(Asanas::class)
            ->call('openProgram', $program->id)
            ->call('openLibrary')
            ->call('setAsanaCategory', $asana->id, 'Асаны сидя и лежа');

        $this->assertSame('Асаны сидя и лежа', $asana->fresh()->category);
    }

    public function test_page_does_not_touch_ready_poses(): void
    {
        $ready = Asana::query()->where('name', 'Тадасана')->firstOrFail();
        $program = AsanaProgram::create(['title' => 'Вечер']);

        Livewire::test(Asanas::class)
            ->call('openProgram', $program->id)
            ->call('openLibrary')
            ->call('setAsanaCategory', $ready->id, 'Асаны сидя и лежа');

        $this->assertSame('Асаны стоя', $ready->fresh()->category, 'Готовая поза меняться не должна.');
    }
}
