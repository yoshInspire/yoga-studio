<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Уменьшенная копия картинки рядом с оригиналом.
 *
 * Зачем: студия грузит снимки прямо из инстаграма — 1920×1920 и под мегабайту
 * весом. Сайту это ещё сходит с рук, а приложение показывает те же файлы в
 * карточках 216 pt и в превью 88 pt, и открытие ленты новостей стоило около
 * десяти мегабайт трафика.
 *
 * Копия только уменьшает, но **не кадрирует**: какую часть кадра показать,
 * решает уже приложение (и решает по-разному на разных экранах), а обрезать
 * заранее — значит навсегда потерять края снимка.
 *
 * Оригинал остаётся на месте: он нужен сайту и как исходник, если однажды
 * понадобится копия другого размера.
 */
final class ImageThumbnailer
{
    /**
     * Длинная сторона копии.
     *
     * Самое крупное место показа — шапка новости во всю ширину экрана: 375 pt
     * при трёхкратной плотности это 1125 px. Берём 1080: на глаз разницы нет,
     * а вес падает с ~900 КБ до ~150.
     */
    public const MAX_SIDE = 1080;

    private const QUALITY = 80;

    /** Подкаталог рядом с оригиналом: `news/x.jpg` → `news/thumbs/x.jpg`. */
    private const DIRECTORY = 'thumbs';

    /** Путь копии для оригинала — существует она или нет. */
    public static function pathFor(string $path): string
    {
        $dir = trim(dirname($path), '.'.DIRECTORY_SEPARATOR.'/');
        $name = pathinfo($path, PATHINFO_FILENAME).'.jpg';

        return ($dir === '' ? '' : $dir.'/').self::DIRECTORY.'/'.$name;
    }

    /** Путь готовой копии либо null — тогда показывают оригинал. */
    public static function existing(string $path, string $disk = 'public'): ?string
    {
        $thumb = self::pathFor($path);

        return Storage::disk($disk)->exists($thumb) ? $thumb : null;
    }

    /**
     * Сделать копию, если её ещё нет.
     *
     * @return string|null путь копии; null — копия не нужна или не получилась
     *                     (маленький оригинал, чужой формат, нет GD)
     */
    public static function ensure(string $path, string $disk = 'public'): ?string
    {
        $storage = Storage::disk($disk);

        if (! $storage->exists($path)) {
            return null;
        }

        $thumb = self::pathFor($path);

        if ($storage->exists($thumb)) {
            return $thumb;
        }

        if (! function_exists('imagecreatefromstring')) {
            return null;
        }

        $source = $storage->path($path);
        $dimensions = ImageMemoryGuard::dimensions($source);

        if ($dimensions === null) {
            return null;
        }

        [$width, $height] = $dimensions;
        $longest = max($width, $height);

        // Оригинал и так небольшой — второй файл только занимал бы место.
        if ($longest <= self::MAX_SIDE) {
            return null;
        }

        $scale = self::MAX_SIDE / $longest;
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        if (! ImageMemoryGuard::fits($width * $height, $targetWidth * $targetHeight)) {
            return null;
        }

        $image = @imagecreatefromstring((string) file_get_contents($source));

        if ($image === false) {
            return null;
        }

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        // У JPEG нет прозрачности: без белой заливки PNG с альфой приезжает
        // с чёрными полями (на этом уже наступали с аватарами).
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
        imagedestroy($image);

        ob_start();
        imagejpeg($canvas, null, self::QUALITY);
        $binary = (string) ob_get_clean();
        imagedestroy($canvas);

        $storage->put($thumb, $binary);

        return $thumb;
    }

    /** Убрать копию — например, когда картинку в новости заменили. */
    public static function forget(string $path, string $disk = 'public'): void
    {
        Storage::disk($disk)->delete(self::pathFor($path));
    }
}
