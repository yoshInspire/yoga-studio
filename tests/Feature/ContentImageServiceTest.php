<?php

namespace Tests\Feature;

use App\Services\ContentImageService;
use App\Support\ImageThumbnailer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Приём картинок контента — общая основа для новостей, направлений и снимка
 * тренера на витрине (ADMIN_PLAN_2.md, фундамент).
 *
 * Главное, что здесь проверяется, — что сервис **не кадрирует**: обрезка на
 * сервере уже дважды стоила студии срезанных голов на квадратных снимках из
 * инстаграма, и форму кадра выбирает тот, кто показывает.
 */
class ContentImageServiceTest extends TestCase
{
    private function service(): ContentImageService
    {
        return app(ContentImageService::class);
    }

    /** Настоящий JPEG заданного размера — GD в тестах работает как на проде. */
    private function image(int $width, int $height): UploadedFile
    {
        return UploadedFile::fake()->image('shot.jpg', $width, $height);
    }

    /** @return array{0: int, 1: int} */
    private function sizeOf(string $disk, string $path): array
    {
        $size = getimagesize(Storage::disk($disk)->path($path));

        return [(int) $size[0], (int) $size[1]];
    }

    public function test_large_photo_is_downscaled_to_the_long_side_limit(): void
    {
        Storage::fake('public');

        $path = $this->service()->store($this->image(4000, 3000), 'public', 'news');

        Storage::disk('public')->assertExists($path);
        $this->assertSame([ContentImageService::MAX_SIDE, 1440], $this->sizeOf('public', $path));
    }

    public function test_proportions_survive_and_nothing_is_cropped(): void
    {
        Storage::fake('public');

        // Квадрат из инстаграма — тот самый случай, ради которого сервис
        // не кадрирует: он обязан остаться квадратом.
        $square = $this->service()->store($this->image(1920, 1920), 'public', 'news');
        $this->assertSame([1920, 1920], $this->sizeOf('public', $square));

        // Панорама остаётся панорамой, а не превращается в квадрат.
        $wide = $this->service()->store($this->image(3000, 1000), 'public', 'news');
        [$width, $height] = $this->sizeOf('public', $wide);
        $this->assertSame(ContentImageService::MAX_SIDE, $width);
        $this->assertSame(640, $height);
    }

    public function test_small_photo_keeps_its_size(): void
    {
        Storage::fake('public');

        $path = $this->service()->store($this->image(800, 600), 'public', 'news');

        $this->assertSame([800, 600], $this->sizeOf('public', $path));
    }

    public function test_file_lands_in_the_given_directory_on_the_given_disk(): void
    {
        Storage::fake('public');
        Storage::fake('public_web');

        $path = $this->service()->store($this->image(600, 600), 'public_web', 'images/directions/hatha');

        $this->assertStringStartsWith('images/directions/hatha/', $path);
        $this->assertStringEndsWith('.jpg', $path);
        Storage::disk('public_web')->assertExists($path);
        // Диски не путаются: в соседнем ничего не появилось.
        Storage::disk('public')->assertMissing($path);
    }

    public function test_previous_file_and_its_thumbnail_are_removed(): void
    {
        Storage::fake('public');

        $first = $this->service()->store($this->image(2400, 2400), 'public', 'news');
        $thumb = ImageThumbnailer::ensure($first, 'public');

        $this->assertNotNull($thumb, 'Копия должна была появиться: оригинал больше предела.');
        Storage::disk('public')->assertExists($thumb);

        $second = $this->service()->store($this->image(600, 600), 'public', 'news', previous: $first);

        Storage::disk('public')->assertExists($second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertMissing($thumb);
    }

    public function test_delete_removes_file_and_thumbnail(): void
    {
        Storage::fake('public');

        $path = $this->service()->store($this->image(2400, 1800), 'public', 'news');
        $thumb = ImageThumbnailer::ensure($path, 'public');

        $this->service()->delete($path, 'public');

        Storage::disk('public')->assertMissing($path);
        Storage::disk('public')->assertMissing($thumb);
    }

    public function test_delete_of_nothing_is_harmless(): void
    {
        Storage::fake('public');

        $this->service()->delete(null, 'public');
        $this->service()->delete('', 'public');

        $this->assertTrue(true);
    }

    public function test_file_that_is_not_an_image_is_rejected_with_a_readable_message(): void
    {
        Storage::fake('public');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Не удалось прочитать фотографию.');

        $this->service()->store(UploadedFile::fake()->create('notes.pdf', 10), 'public', 'news');
    }
}
