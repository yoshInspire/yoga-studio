<?php

namespace Tests\Feature;

use App\Models\Asana;
use App\Models\AsanaProgram;
use App\Models\AsanaProgramItem;
use App\Support\AsanaPrintLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Раскладка печати подбирается под желаемое число листов: чем меньше колонок,
 * тем крупнее позы, поэтому берётся самая крупная сетка, которая ещё влезает.
 */
class AsanaPrintLayoutTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('Нужно расширение GD.');
        }
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

    /** Реальный PNG нужного формата: раскладка смотрит на пропорции. */
    private function image(int $width, int $height): string
    {
        $relative = 'images/asanas/library/__print/'.Str::uuid()->toString().'.png';
        $absolute = public_path($relative);
        File::ensureDirectoryExists(dirname($absolute));

        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagepng($image, $absolute);
        imagedestroy($image);

        $this->created[] = $relative;

        return $relative;
    }

    /** @return \Illuminate\Support\Collection<int, AsanaProgramItem> */
    private function items(int $normal, int $wide = 0)
    {
        $program = AsanaProgram::create(['title' => 'Занятие']);
        $position = 0;

        $normalPath = $normal > 0 ? $this->image(143, 106) : null;
        $widePath = $wide > 0 ? $this->image(596, 178) : null;

        for ($i = 0; $i < $wide; $i++) {
            $asana = Asana::create(['name' => 'Панорама '.$i, 'image_path' => $widePath]);
            AsanaProgramItem::create([
                'program_id' => $program->id, 'asana_id' => $asana->id, 'position' => $position++,
            ]);
        }

        for ($i = 0; $i < $normal; $i++) {
            $asana = Asana::create(['name' => 'Поза '.$i, 'image_path' => $normalPath]);
            AsanaProgramItem::create([
                'program_id' => $program->id, 'asana_id' => $asana->id, 'position' => $position++,
            ]);
        }

        return $program->items()->with('asana')->get();
    }

    public function test_auto_layout_keeps_three_columns(): void
    {
        $layout = AsanaPrintLayout::forItems($this->items(9));

        $this->assertSame(3, $layout['columns']);
    }

    public function test_few_poses_on_one_page_stay_large(): void
    {
        // Четыре позы помещаются в два ряда самой крупной сеткой.
        $layout = AsanaPrintLayout::forItems($this->items(4), 1);

        $this->assertSame(2, $layout['columns']);
        $this->assertSame(1, $layout['pages']);
        $this->assertGreaterThan(60, $layout['image_mm']);
    }

    /** Как только в две колонки не влезает — сетка становится плотнее. */
    public function test_layout_steps_down_when_two_columns_overflow(): void
    {
        $layout = AsanaPrintLayout::forItems($this->items(8), 1);

        $this->assertGreaterThan(2, $layout['columns']);
        $this->assertSame(1, $layout['pages']);
    }

    public function test_many_poses_on_one_page_get_denser(): void
    {
        $loose = AsanaPrintLayout::forItems($this->items(24), 0);
        $tight = AsanaPrintLayout::forItems($this->items(24), 1);

        $this->assertSame(1, $tight['pages']);
        $this->assertGreaterThan($loose['columns'], $tight['columns'], 'Колонок должно стать больше.');
        $this->assertLessThan($loose['image_mm'], $tight['image_mm'], 'Картинки должны стать мельче.');
    }

    public function test_two_pages_allow_bigger_poses_than_one(): void
    {
        $onePage = AsanaPrintLayout::forItems($this->items(24), 1);
        $twoPages = AsanaPrintLayout::forItems($this->items(24), 2);

        $this->assertGreaterThan($onePage['image_mm'], $twoPages['image_mm']);
        $this->assertLessThanOrEqual(2, $twoPages['pages']);
    }

    public function test_impossible_request_falls_back_to_densest_grid(): void
    {
        // Столько поз на один лист не влезет даже самой плотной сеткой.
        $layout = AsanaPrintLayout::forItems($this->items(400), 1);

        $this->assertSame(14, $layout['columns']);
        $this->assertGreaterThan(1, $layout['pages'], 'Честно сообщаем, что листов будет больше.');
    }

    public function test_panorama_takes_a_whole_row(): void
    {
        $withoutWide = AsanaPrintLayout::forItems($this->items(6), 0);
        $withWide = AsanaPrintLayout::forItems($this->items(6, 1), 0);

        $this->assertSame($withoutWide['columns'], $withWide['columns']);
        $this->assertGreaterThanOrEqual($withoutWide['pages'], $withWide['pages']);
    }

    public function test_empty_program_is_one_page(): void
    {
        $layout = AsanaPrintLayout::forItems($this->items(0), 1);

        $this->assertSame(1, $layout['pages']);
    }

    public function test_image_height_matches_column_width(): void
    {
        $layout = AsanaPrintLayout::forItems($this->items(6), 0);

        $this->assertEqualsWithDelta($layout['cell_mm'] * 3 / 4, $layout['image_mm'], 0.2);
    }

    /** Ради чего всё: целая практика должна влезать на один лист. */
    public function test_hundred_poses_fit_on_a_single_page(): void
    {
        $layout = AsanaPrintLayout::forItems($this->items(100), 1);

        $this->assertSame(1, $layout['pages']);
        $this->assertGreaterThanOrEqual(10, $layout['columns']);
        $this->assertGreaterThan(10, $layout['cell_mm'], 'Человечек не должен схлопнуться в точку.');
    }

    public function test_seventy_poses_fit_on_a_single_page(): void
    {
        $layout = AsanaPrintLayout::forItems($this->items(70), 1);

        $this->assertSame(1, $layout['pages']);
        $this->assertGreaterThan(12, $layout['cell_mm']);
    }

    /** Плотная сетка ужимает и подписи, иначе они не помещаются в ячейку. */
    public function test_dense_grid_shrinks_captions_and_gaps(): void
    {
        $loose = AsanaPrintLayout::forItems($this->items(6), 0);
        $dense = AsanaPrintLayout::forItems($this->items(100), 1);

        $this->assertLessThan($loose['caption_pt'], $dense['caption_pt']);
        $this->assertLessThan($loose['gap_x_mm'], $dense['gap_x_mm']);
        $this->assertGreaterThanOrEqual(4.5, $dense['caption_pt'], 'Мельче 4.5 pt читать нечего.');
    }
}
