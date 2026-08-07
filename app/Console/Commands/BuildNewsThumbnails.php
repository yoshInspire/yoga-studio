<?php

namespace App\Console\Commands;

use App\Models\News;
use App\Support\ImageThumbnailer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Уменьшенные копии для новостей, загруженных до появления ImageThumbnailer.
 *
 * Новые картинки уменьшает NewsObserver при сохранении; эта команда нужна один
 * раз после выката и потом — если копии почему-то удалили.
 */
class BuildNewsThumbnails extends Command
{
    protected $signature = 'news:thumbnails {--force : пересобрать даже те, что уже есть}';

    protected $description = 'Сделать уменьшенные копии картинок новостей для приложения';

    public function handle(): int
    {
        $items = News::query()->whereNotNull('image_path')->get();
        $disk = Storage::disk('public');

        $made = 0;
        $skipped = 0;
        $savedBytes = 0;

        foreach ($items as $news) {
            $path = (string) $news->image_path;

            if ($this->option('force')) {
                ImageThumbnailer::forget($path);
            }

            $before = $disk->exists($path) ? $disk->size($path) : 0;
            $thumb = ImageThumbnailer::ensure($path);

            if ($thumb === null) {
                $skipped++;
                $this->line("· {$path} — копия не нужна или не получилась");

                continue;
            }

            $made++;
            $savedBytes += max(0, $before - $disk->size($thumb));
            $this->line(sprintf(
                '✓ %s — %d КБ вместо %d КБ',
                $path,
                (int) round($disk->size($thumb) / 1024),
                (int) round($before / 1024),
            ));
        }

        $this->info(sprintf(
            'Готово: %d копий, %d пропущено, экономия %d МБ на полную ленту.',
            $made,
            $skipped,
            (int) round($savedBytes / 1024 / 1024),
        ));

        return self::SUCCESS;
    }
}
