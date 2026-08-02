<?php

namespace Tests\Feature;

use App\Models\Asana;
use App\Models\AsanaFolder;
use App\Models\AsanaProgram;
use App\Models\AsanaProgramItem;
use App\Services\AsanaProgramService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Tests\TestCase;

class AsanaProgramTest extends TestCase
{
    use RefreshDatabase;

    private AsanaProgramService $service;

    /** @var list<string> */
    private array $createdFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(AsanaProgramService::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdFiles as $path) {
            $absolute = public_path($path);

            if (File::exists($absolute)) {
                File::delete($absolute);
            }
        }

        // Пустая папка от тестовых картинок не должна оставаться в репозитории.
        $testDir = public_path('images/asanas/library/__test');

        if (File::isDirectory($testDir) && File::files($testDir) === []) {
            File::deleteDirectory($testDir);
        }

        parent::tearDown();
    }

    /** Минимальный валидный PNG 1x1 в виде data-URL из холста. */
    private function pngDataUrl(): string
    {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        );

        return 'data:image/png;base64,'.base64_encode($png);
    }

    private function asana(string $name = 'Тадасана'): Asana
    {
        return Asana::create([
            'name' => $name,
            'category' => 'Асаны стоя',
            'image_path' => 'images/asanas/library/asany-stoya/'.md5($name).'.png',
        ]);
    }

    private function program(): AsanaProgram
    {
        return AsanaProgram::create(['title' => 'Растяжка вечер']);
    }

    private function track(?string $path): void
    {
        if (filled($path)) {
            $this->createdFiles[] = $path;
        }
    }

    public function test_poses_are_added_in_order(): void
    {
        $program = $this->program();

        $first = $this->service->addAsana($program, $this->asana('Первая'));
        $second = $this->service->addAsana($program, $this->asana('Вторая'));

        $this->assertLessThan($second->position, $first->position);
        $this->assertSame(
            ['Первая', 'Вторая'],
            $program->items()->with('asana')->get()->map(fn ($i) => $i->asana->name)->all(),
        );
    }

    public function test_move_swaps_neighbours(): void
    {
        $program = $this->program();
        $first = $this->service->addAsana($program, $this->asana('Первая'));
        $second = $this->service->addAsana($program, $this->asana('Вторая'));

        $this->service->move($second, -1);

        $this->assertSame(
            [$second->id, $first->id],
            $program->items()->get()->pluck('id')->all(),
        );
    }

    public function test_move_at_the_edge_does_nothing(): void
    {
        $program = $this->program();
        $first = $this->service->addAsana($program, $this->asana('Первая'));
        $this->service->addAsana($program, $this->asana('Вторая'));

        $this->service->move($first, -1);

        $this->assertSame($first->id, $program->items()->first()->id);
    }

    public function test_reorder_applies_dragged_order(): void
    {
        $program = $this->program();
        $a = $this->service->addAsana($program, $this->asana('А'));
        $b = $this->service->addAsana($program, $this->asana('Б'));
        $c = $this->service->addAsana($program, $this->asana('В'));

        $this->service->reorder($program, [$c->id, $a->id, $b->id]);

        $this->assertSame(
            [$c->id, $a->id, $b->id],
            $program->items()->get()->pluck('id')->all(),
        );
    }

    public function test_reorder_keeps_missing_items_at_the_end(): void
    {
        $program = $this->program();
        $a = $this->service->addAsana($program, $this->asana('А'));
        $b = $this->service->addAsana($program, $this->asana('Б'));

        $this->service->reorder($program, [$b->id]);

        $this->assertSame([$b->id, $a->id], $program->items()->get()->pluck('id')->all());
    }

    public function test_drawing_creates_custom_asana_in_library(): void
    {
        $asana = $this->service->storeCustomAsana($this->pngDataUrl(), 'Мостик');
        $this->track($asana->image_path);

        $this->assertTrue($asana->is_custom);
        $this->assertSame('Мостик', $asana->name);
        $this->assertTrue(File::exists(public_path($asana->image_path)));
        $this->assertStringStartsWith(AsanaProgramService::CUSTOM_DIR.'/', $asana->image_path);
    }

    public function test_unnamed_drawing_gets_a_default_name(): void
    {
        $asana = $this->service->storeCustomAsana($this->pngDataUrl(), '   ');
        $this->track($asana->image_path);

        $this->assertSame('Своя поза', $asana->name);
    }

    public function test_editing_a_pose_does_not_touch_the_library(): void
    {
        $program = $this->program();
        $asana = $this->asana('Адхо мукха шванасана');
        $original = $asana->image_path;
        $item = $this->service->addAsana($program, $asana);

        $item = $this->service->storeItemDrawing($item, $this->pngDataUrl());
        $this->track($item->image_path);

        $this->assertSame($original, $asana->fresh()->image_path, 'Базовая поза не должна меняться.');
        $this->assertNotNull($item->image_path);
        $this->assertTrue($item->isEdited());
        $this->assertSame($item->image_path, $item->effectiveImagePath());
    }

    public function test_reset_returns_the_original_pose(): void
    {
        $program = $this->program();
        $asana = $this->asana();
        $item = $this->service->addAsana($program, $asana);
        $item = $this->service->storeItemDrawing($item, $this->pngDataUrl());
        $drawn = public_path($item->image_path);

        $item = $this->service->resetItemDrawing($item);

        $this->assertNull($item->image_path);
        $this->assertSame($asana->image_path, $item->effectiveImagePath());
        $this->assertFalse(File::exists($drawn), 'Файл правки должен убираться за собой.');
    }

    public function test_rejects_anything_that_is_not_png(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->storeCustomAsana('data:image/svg+xml;base64,'.base64_encode('<svg/>'));
    }

    public function test_rejects_png_header_forgery(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->storeCustomAsana('data:image/png;base64,'.base64_encode('не картинка'));
    }

    public function test_removing_a_pose_deletes_its_drawing(): void
    {
        $program = $this->program();
        $item = $this->service->addAsana($program, $this->asana());
        $item = $this->service->storeItemDrawing($item, $this->pngDataUrl());
        $path = public_path($item->image_path);

        $this->service->remove($item);

        $this->assertFalse(File::exists($path));
        $this->assertSame(0, $program->items()->count());
    }

    public function test_removing_a_pose_keeps_the_library_file(): void
    {
        $program = $this->program();
        $asana = $this->asana();
        $libraryFile = public_path($asana->image_path);
        File::ensureDirectoryExists(dirname($libraryFile));
        File::put($libraryFile, 'x');
        $this->track($asana->image_path);

        $this->service->remove($this->service->addAsana($program, $asana));

        $this->assertTrue(File::exists($libraryFile), 'Библиотечная картинка не должна удаляться.');
    }

    public function test_duplicate_copies_poses_and_drops_edits(): void
    {
        $program = $this->program();
        $asana = $this->asana();
        $item = $this->service->addAsana($program, $asana);
        $item->update(['note' => 'на 5 дыханий']);
        $edited = $this->service->storeItemDrawing($item, $this->pngDataUrl());
        $this->track($edited->image_path);

        $copy = $this->service->duplicate($program);

        $this->assertNotSame($program->id, $copy->id);
        $this->assertSame(1, $copy->items()->count());

        $copied = $copy->items()->first();
        $this->assertSame($asana->id, $copied->asana_id);
        $this->assertSame('на 5 дыханий', $copied->note);
        $this->assertNull($copied->image_path, 'Копия начинает с чистой позы.');
    }

    public function test_duplicate_gives_own_file_to_a_pose_without_library_origin(): void
    {
        $program = $this->program();
        $drawn = $this->service->storeCustomAsana($this->pngDataUrl());
        $this->track($drawn->image_path);

        // Элемент держится только на своей картинке, без ссылки на библиотеку.
        $item = AsanaProgramItem::create([
            'program_id' => $program->id,
            'asana_id' => null,
            'image_path' => $drawn->image_path,
            'position' => 1,
        ]);

        $copy = $this->service->duplicate($program);
        $copied = $copy->items()->first();
        $this->track($copied->image_path);

        $this->assertNotSame(
            $item->image_path,
            $copied->image_path,
            'Общий файл на две программы означал бы, что удаление одной ломает другую.',
        );
        $this->assertTrue(File::exists(public_path($copied->image_path)));

        // Удаление оригинала не должно осиротить копию.
        $this->service->remove($item->fresh());
        $this->assertTrue(File::exists(public_path($copied->image_path)));
    }

    public function test_custom_asana_in_use_is_not_deleted(): void
    {
        $program = $this->program();
        $drawn = $this->service->storeCustomAsana($this->pngDataUrl());
        $this->track($drawn->image_path);
        $this->service->addAsana($program, $drawn);

        $usedIn = $this->service->deleteCustomAsana($drawn);

        $this->assertSame(1, $usedIn);
        $this->assertNotNull($drawn->fresh(), 'Поза из занятия не должна исчезать молча.');
    }

    public function test_unused_custom_asana_is_deleted_with_its_file(): void
    {
        $drawn = $this->service->storeCustomAsana($this->pngDataUrl());
        $path = public_path($drawn->image_path);

        $usedIn = $this->service->deleteCustomAsana($drawn);

        $this->assertSame(0, $usedIn);
        $this->assertNull($drawn->fresh());
        $this->assertFalse(File::exists($path));
    }

    public function test_library_asana_cannot_be_deleted_as_custom(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->deleteCustomAsana($this->asana());
    }

    public function test_folder_breadcrumbs_walk_up_to_the_root(): void
    {
        $root = AsanaFolder::create(['name' => 'Растяжка']);
        $child = AsanaFolder::create(['name' => 'Шпагаты', 'parent_id' => $root->id]);

        $this->assertSame(
            ['Растяжка', 'Шпагаты'],
            collect($child->breadcrumbs())->pluck('name')->all(),
        );
    }

    public function test_deleting_a_folder_keeps_its_programs(): void
    {
        $folder = AsanaFolder::create(['name' => 'Растяжка']);
        $program = AsanaProgram::create(['title' => 'Шпагаты', 'folder_id' => $folder->id]);

        $folder->delete();

        $this->assertNotNull($program->fresh(), 'Занятие не должно удаляться вместе с папкой.');
        $this->assertNull($program->fresh()->folder_id);
    }

    public function test_deleting_a_program_removes_its_poses(): void
    {
        $program = $this->program();
        $this->service->addAsana($program, $this->asana());

        $program->delete();

        $this->assertSame(0, AsanaProgramItem::count());
    }

    /** Панорамный лист вроде «Сурья намаскар» печатается на всю ширину строки. */
    public function test_panoramic_image_is_detected_as_wide(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('Нужен GD для создания тестовой картинки.');
        }

        $program = $this->program();

        $wide = $this->makeImage('wide', 596, 178);
        $normal = $this->makeImage('normal', 143, 106);

        $wideItem = $this->service->addAsana($program, Asana::create([
            'name' => 'Сурья намаскар', 'image_path' => $wide,
        ]));
        $normalItem = $this->service->addAsana($program, Asana::create([
            'name' => 'Тадасана', 'image_path' => $normal,
        ]));

        $this->assertSame(3.348, $wideItem->aspectRatio());
        $this->assertTrue($wideItem->isWideImage());

        $this->assertSame(1.349, $normalItem->aspectRatio());
        $this->assertFalse($normalItem->isWideImage());
    }

    public function test_item_without_image_is_not_wide(): void
    {
        $item = AsanaProgramItem::create([
            'program_id' => $this->program()->id,
            'asana_id' => null,
            'position' => 1,
        ]);

        $this->assertSame(0.0, $item->aspectRatio());
        $this->assertFalse($item->isWideImage());
    }

    /** Создаёт настоящий PNG заданного размера и возвращает путь от public/. */
    private function makeImage(string $name, int $width, int $height): string
    {
        $relative = 'images/asanas/library/__test/'.$name.'-'.$width.'x'.$height.'.png';
        $absolute = public_path($relative);

        File::ensureDirectoryExists(dirname($absolute));

        $image = imagecreatetruecolor($width, $height);
        imagepng($image, $absolute);
        imagedestroy($image);

        $this->track($relative);

        return $relative;
    }

    public function test_image_url_is_root_relative(): void
    {
        $asana = $this->asana();

        $this->assertStringStartsWith('/', $asana->imageUrl());
        $this->assertStringNotContainsString('://', $asana->imageUrl());
    }
}
