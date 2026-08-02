<?php

namespace App\Console\Commands;

use App\Models\Asana;
use App\Models\AsanaProgramItem;
use App\Services\AsanaProgramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Разовая обрезка полей у зарисовок, сохранённых до появления обрезки на холсте.
 *
 * Раньше в файл уезжал весь холст: фигурка занимала малую его часть, а вокруг
 * оставалось белое поле — рядом с библиотечной позой такая зарисовка выглядела
 * мелкой. Новые зарисовки обрезаются сразу в браузере, а эта команда приводит
 * к тому же виду уже сохранённые.
 */
class TrimCustomAsanas extends Command
{
    protected $signature = 'asanas:trim-custom
        {--dry-run : Показать, что будет обрезано, ничего не меняя}';

    protected $description = 'Обрезать белые поля у ранее сохранённых зарисовок асан';

    /** Ширина итоговой картинки; высота считается из соотношения. */
    private const OUTPUT_WIDTH = 600;

    private const ASPECT = 4 / 3;

    /**
     * Если рисунок уже занимает столько по ширине — трогать нечего.
     * Готовые асаны из библиотеки заполняют кадр на 59–66%, и обрезка на
     * холсте даёт примерно столько же. Порог ниже этого диапазона, чтобы
     * повторный запуск команды ничего не менял.
     */
    private const ALREADY_TIGHT = 0.50;

    public function handle(): int
    {
        if (! function_exists('imagecreatefrompng')) {
            $this->error('Нужно расширение GD.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $paths = $this->customImagePaths();

        $this->info('Найдено зарисовок: '.count($paths));

        $trimmed = 0;
        $skipped = 0;

        foreach ($paths as $path) {
            $absolute = public_path($path);

            if (! File::exists($absolute)) {
                $this->warn('  нет файла: '.$path);

                continue;
            }

            $result = $this->trim($absolute, $dryRun);

            if ($result === null) {
                $skipped++;

                continue;
            }

            $this->line(sprintf(
                '  %s: %s → %s%s',
                basename($path),
                $result['before'],
                $result['after'],
                $dryRun ? ' (dry-run)' : '',
            ));

            $trimmed++;
        }

        $this->info(sprintf(
            '%sОбрезано: %d, оставлено без изменений: %d.',
            $dryRun ? '[dry-run] ' : '',
            $trimmed,
            $skipped,
        ));

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function customImagePaths(): array
    {
        $fromLibrary = Asana::query()
            ->where('is_custom', true)
            ->pluck('image_path');

        $fromItems = AsanaProgramItem::query()
            ->whereNotNull('image_path')
            ->pluck('image_path');

        return $fromLibrary
            ->merge($fromItems)
            ->filter(fn (?string $p): bool => filled($p)
                && str_starts_with($p, AsanaProgramService::CUSTOM_DIR.'/'))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array{before: string, after: string}|null  null — обрезать нечего
     */
    private function trim(string $absolute, bool $dryRun): ?array
    {
        $source = @imagecreatefrompng($absolute);

        if ($source === false) {
            return null;
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $bounds = $this->contentBounds($source, $width, $height);

        if ($bounds === null) {
            imagedestroy($source);

            return null;
        }

        [$minX, $minY, $maxX, $maxY] = $bounds;
        $contentWidth = $maxX - $minX + 1;

        if ($contentWidth / $width >= self::ALREADY_TIGHT) {
            imagedestroy($source);

            return null;
        }

        $pad = (int) round(min($width, $height) * 0.02);
        $minX = max(0, $minX - $pad);
        $minY = max(0, $minY - $pad);
        $maxX = min($width - 1, $maxX + $pad);
        $maxY = min($height - 1, $maxY + $pad);

        $cropW = $maxX - $minX + 1;
        $cropH = $maxY - $minY + 1;
        $centerX = $minX + $cropW / 2;
        $centerY = $minY + $cropH / 2;

        // Дотягиваем до тех же пропорций, что у готовых асан.
        if ($cropW / $cropH < self::ASPECT) {
            $cropW = $cropH * self::ASPECT;
        } else {
            $cropH = $cropW / self::ASPECT;
        }

        $outWidth = self::OUTPUT_WIDTH;
        $outHeight = (int) round(self::OUTPUT_WIDTH / self::ASPECT);

        $before = $width.'x'.$height;
        $after = $outWidth.'x'.$outHeight;

        if ($dryRun) {
            imagedestroy($source);

            return ['before' => $before, 'after' => $after];
        }

        $out = imagecreatetruecolor($outWidth, $outHeight);
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));

        imagecopyresampled(
            $out, $source,
            0, 0,
            (int) round($centerX - $cropW / 2), (int) round($centerY - $cropH / 2),
            $outWidth, $outHeight,
            (int) round($cropW), (int) round($cropH),
        );

        imagepng($out, $absolute);
        imagedestroy($out);
        imagedestroy($source);

        return ['before' => $before, 'after' => $after];
    }

    /**
     * Границы нарисованного: всё заметно темнее белого фона.
     *
     * @return array{0: int, 1: int, 2: int, 3: int}|null
     */
    private function contentBounds(\GdImage $image, int $width, int $height): ?array
    {
        $minX = $width;
        $minY = $height;
        $maxX = -1;
        $maxY = -1;

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgb = imagecolorat($image, $x, $y);

                if ((($rgb >> 16) & 0xFF) >= 240
                    && (($rgb >> 8) & 0xFF) >= 240
                    && ($rgb & 0xFF) >= 240) {
                    continue;
                }

                if ($x < $minX) {
                    $minX = $x;
                }
                if ($x > $maxX) {
                    $maxX = $x;
                }
                if ($y < $minY) {
                    $minY = $y;
                }
                if ($y > $maxY) {
                    $maxY = $y;
                }
            }
        }

        return $maxX < 0 ? null : [$minX, $minY, $maxX, $maxY];
    }
}
