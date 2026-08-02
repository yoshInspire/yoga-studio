<?php

namespace Tests\Feature;

use App\Models\Asana;
use App\Services\AsanaProgramService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Зарисовки, сохранённые до появления обрезки на холсте, занимали малую часть
 * кадра и рядом с готовыми асанами выглядели мелкими. Команда приводит их
 * к тому же заполнению, что и библиотечные позы.
 */
class TrimCustomAsanasTest extends TestCase
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

    /** Белый холст с чёрным прямоугольником заданной доли по ширине. */
    private function drawing(int $width, int $height, float $fill): string
    {
        $relative = AsanaProgramService::CUSTOM_DIR.'/'.Str::uuid()->toString().'.png';
        $absolute = public_path($relative);
        File::ensureDirectoryExists(dirname($absolute));

        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));

        $w = (int) round($width * $fill);
        $h = (int) round($height * $fill);
        imagefilledrectangle($image, 20, 20, 20 + $w, 20 + $h, imagecolorallocate($image, 17, 24, 39));

        imagepng($image, $absolute);
        imagedestroy($image);

        $this->created[] = $relative;

        return $relative;
    }

    /** Какую долю ширины занимает нарисованное. */
    private function fillRatio(string $relative): float
    {
        $image = imagecreatefrompng(public_path($relative));
        $width = imagesx($image);
        $height = imagesy($image);

        $minX = $width;
        $maxX = -1;

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                if ((imagecolorat($image, $x, $y) >> 16 & 0xFF) < 240) {
                    $minX = min($minX, $x);
                    $maxX = max($maxX, $x);
                }
            }
        }

        imagedestroy($image);

        return $maxX < 0 ? 0.0 : ($maxX - $minX + 1) / $width;
    }

    private function imageSize(string $relative): array
    {
        $image = imagecreatefrompng(public_path($relative));
        $size = [imagesx($image), imagesy($image)];
        imagedestroy($image);

        return $size;
    }

    public function test_small_drawing_is_enlarged_to_fill_the_frame(): void
    {
        // Как у клиента: фигурка занимает малую часть большого холста.
        $path = $this->drawing(1280, 960, 0.12);
        Asana::create(['name' => 'Своя поза', 'image_path' => $path, 'is_custom' => true]);

        $this->assertLessThan(0.2, $this->fillRatio($path));

        $this->artisan('asanas:trim-custom')->assertSuccessful();

        $this->assertSame([600, 450], $this->imageSize($path));
        $this->assertGreaterThan(0.5, $this->fillRatio($path), 'Рисунок должен заполнить кадр.');
    }

    public function test_already_tight_drawing_is_left_alone(): void
    {
        $path = $this->drawing(600, 450, 0.75);
        Asana::create(['name' => 'Уже плотная', 'image_path' => $path, 'is_custom' => true]);

        $before = File::get(public_path($path));

        $this->artisan('asanas:trim-custom')->assertSuccessful();

        $this->assertSame($before, File::get(public_path($path)), 'Файл не должен меняться.');
    }

    public function test_second_run_changes_nothing(): void
    {
        $path = $this->drawing(1280, 960, 0.12);
        Asana::create(['name' => 'Своя поза', 'image_path' => $path, 'is_custom' => true]);

        $this->artisan('asanas:trim-custom')->assertSuccessful();
        $afterFirst = File::get(public_path($path));

        $this->artisan('asanas:trim-custom')->assertSuccessful();

        $this->assertSame($afterFirst, File::get(public_path($path)), 'Команда должна быть идемпотентной.');
    }

    public function test_dry_run_does_not_touch_files(): void
    {
        $path = $this->drawing(1280, 960, 0.12);
        Asana::create(['name' => 'Своя поза', 'image_path' => $path, 'is_custom' => true]);

        $before = File::get(public_path($path));

        $this->artisan('asanas:trim-custom', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame($before, File::get(public_path($path)));
    }

    public function test_library_images_are_never_touched(): void
    {
        // Библиотечная поза лежит вне папки своих зарисовок и в выборку не попадает.
        $relative = 'images/asanas/library/__trimtest/pose.png';
        $absolute = public_path($relative);
        File::ensureDirectoryExists(dirname($absolute));

        $image = imagecreatetruecolor(143, 106);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagefilledrectangle($image, 5, 5, 20, 20, imagecolorallocate($image, 0, 0, 0));
        imagepng($image, $absolute);
        imagedestroy($image);
        $this->created[] = $relative;

        Asana::create(['name' => 'Библиотечная', 'image_path' => $relative, 'is_custom' => false]);
        $before = File::get($absolute);

        $this->artisan('asanas:trim-custom')->assertSuccessful();

        $this->assertSame($before, File::get($absolute));

        File::deleteDirectory(dirname($absolute));
    }
}
