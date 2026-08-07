<?php

namespace Tests\Feature;

use App\Models\News;
use App\Support\ImageThumbnailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class NewsThumbnailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('Без GD картинки не обрабатываются.');
        }
    }

    /** Положить картинку на публичный диск так, как это делает админка. */
    private function storeImage(int $width, int $height, string $name = 'photo.jpg'): string
    {
        $file = UploadedFile::fake()->image($name, $width, $height);
        $path = 'news/'.$name;

        Storage::disk('public')->put($path, (string) file_get_contents($file->getRealPath()));

        return $path;
    }

    private function publishedNews(?string $imagePath): News
    {
        return News::query()->create([
            'title' => 'Зачем нам сильные икры?',
            'body' => 'Текст новости.',
            'image_path' => $imagePath,
            'is_published' => true,
            'published_at' => now()->subDay(),
        ]);
    }

    public function test_thumbnail_is_made_when_news_is_created(): void
    {
        $path = $this->storeImage(1920, 1920);

        $this->publishedNews($path);

        Storage::disk('public')->assertExists('news/thumbs/photo.jpg');
    }

    public function test_thumbnail_keeps_proportions_and_fits_the_limit(): void
    {
        $path = $this->storeImage(1920, 1440, 'wide.jpg');

        ImageThumbnailer::ensure($path);

        $thumb = Storage::disk('public')->path('news/thumbs/wide.jpg');
        [$width, $height] = getimagesize($thumb);

        $this->assertSame(ImageThumbnailer::MAX_SIDE, $width);
        // 1920×1440 — это 4:3, значит 1080×810. Кадрировать копия не должна.
        $this->assertSame(810, $height);
    }

    public function test_small_image_is_left_alone(): void
    {
        $path = $this->storeImage(800, 800, 'small.jpg');

        $this->assertNull(ImageThumbnailer::ensure($path));
        Storage::disk('public')->assertMissing('news/thumbs/small.jpg');
    }

    public function test_replacing_the_picture_drops_the_old_copy(): void
    {
        $first = $this->storeImage(1920, 1920, 'first.jpg');
        $news = $this->publishedNews($first);

        Storage::disk('public')->assertExists('news/thumbs/first.jpg');

        $second = $this->storeImage(1920, 1920, 'second.jpg');
        $news->update(['image_path' => $second]);

        Storage::disk('public')->assertMissing('news/thumbs/first.jpg');
        Storage::disk('public')->assertExists('news/thumbs/second.jpg');
    }

    public function test_api_gives_the_reduced_copy_and_keeps_the_original(): void
    {
        $news = $this->publishedNews($this->storeImage(1920, 1920));

        $response = $this->getJson('/api/v1/news');

        $response->assertOk();
        $item = $response->json('data.0');

        $this->assertStringContainsString('news/thumbs/photo.jpg', $item['image_thumb']);
        $this->assertStringContainsString('news/photo.jpg', $item['image']);

        $this->getJson('/api/v1/news/'.$news->slug)
            ->assertOk()
            ->assertJsonPath('data.image_thumb', $item['image_thumb']);
    }

    public function test_api_gives_the_shape_of_the_picture(): void
    {
        $news = $this->publishedNews($this->storeImage(1920, 1440, 'wide.jpg'));

        $ratio = $this->getJson('/api/v1/news')->assertOk()->json('data.0.image_ratio');

        // Приложение подгоняет высоту карточки под эту цифру, поэтому важна
        // форма исходника, а не размеры уменьшенной копии.
        $this->assertEqualsWithDelta(4 / 3, $ratio, 0.01);

        $this->getJson('/api/v1/news/'.$news->slug)
            ->assertOk()
            ->assertJsonPath('data.image_ratio', $ratio);
    }

    public function test_news_without_a_picture_has_no_shape(): void
    {
        $this->publishedNews(null);

        $this->getJson('/api/v1/news')
            ->assertOk()
            ->assertJsonPath('data.0.image_ratio', null);
    }

    public function test_news_without_a_copy_falls_back_to_the_original(): void
    {
        $news = $this->publishedNews($this->storeImage(600, 600, 'tiny.jpg'));

        $this->assertStringContainsString('news/tiny.jpg', (string) $news->imageThumbUrl());
    }

    public function test_command_builds_copies_for_older_news(): void
    {
        $news = $this->publishedNews(null);
        // Мимо наблюдателя: так выглядят новости, загруженные до этой правки.
        $news->updateQuietly(['image_path' => $this->storeImage(1920, 1920, 'old.jpg')]);

        Storage::disk('public')->assertMissing('news/thumbs/old.jpg');

        $this->artisan('news:thumbnails')->assertExitCode(0);

        Storage::disk('public')->assertExists('news/thumbs/old.jpg');
    }
}
