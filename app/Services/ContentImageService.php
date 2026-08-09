<?php

namespace App\Services;

use App\Support\ImageMemoryGuard;
use App\Support\ImageThumbnailer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Приём картинки контента: новости, обложки и галереи направлений, снимок
 * тренера для витрины сайта.
 *
 * Отличается от `AvatarService` тем, что **не кадрирует**. Аватар обязан быть
 * квадратом — он всегда в кружке. Здесь наоборот: форму кадра решает тот, кто
 * показывает. Приложение берёт её из `image_ratio` и подгоняет бокс под снимок
 * (`NewsCover`), а любая обрезка на сервере навсегда отрезала бы у квадратов
 * из инстаграма треть вместе с головами — на этом уже наступали дважды.
 *
 * Что делает: проверяет, что памяти хватит, ужимает длинную сторону до
 * `MAX_SIDE`, приводит к JPEG и кладёт на указанный диск.
 *
 * **Диск передаётся вызывающим, и это не формальность.** Новости и снимки
 * тренеров живут в `public` (`storage/app/public`, наружу через симлинк),
 * направления — в `public_web` (каталог `public/images/...` рядом с сайтом).
 * Перепутать их — значит потерять картинки при следующем деплое.
 */
class ContentImageService
{
    /**
     * Длинная сторона хранимого оригинала.
     *
     * Студия выкладывает кадры из инстаграма (1920×1920), а с камеры телефона
     * приезжает 4000 px и 5 МБ. Сайту хватает 1920 с запасом, приложению рядом
     * лежит копия на 1080 от `ImageThumbnailer`.
     */
    public const MAX_SIDE = 1920;

    private const JPEG_QUALITY = 86;

    /**
     * Сохранить снимок и вернуть путь на диске.
     *
     * @param  string|null  $previous  прежний файл — удаляется вместе с копией
     *
     * @throws RuntimeException если картинку не прочитать или она слишком велика
     */
    public function store(UploadedFile $photo, string $disk, string $directory, ?string $previous = null): string
    {
        $binary = $this->downscale($photo);
        $path = trim($directory, '/').'/'.Str::ulid().'.jpg';

        Storage::disk($disk)->put($path, $binary);

        $this->delete($previous, $disk);

        return $path;
    }

    /** Убрать файл вместе с уменьшенной копией. */
    public function delete(?string $path, string $disk): void
    {
        if ($path === null || $path === '') {
            return;
        }

        ImageThumbnailer::forget($path, $disk);
        Storage::disk($disk)->delete($path);
    }

    /**
     * Ужать длинную сторону до MAX_SIDE, пропорции сохранить.
     *
     * Снимок меньше предела не трогаем по размеру, но всё равно проводим через
     * GD: так на диск всегда ложится JPEG, и сайту с приложением не приходится
     * гадать про формат и прозрачность.
     */
    private function downscale(UploadedFile $photo): string
    {
        if (! function_exists('imagecreatefromstring')) {
            throw new RuntimeException('На сервере нет расширения GD — обработка фотографий недоступна.');
        }

        $dimensions = ImageMemoryGuard::dimensions($photo->getRealPath());

        if ($dimensions === null) {
            throw new RuntimeException('Не удалось прочитать фотографию.');
        }

        [$width, $height] = $dimensions;
        $longest = max($width, $height);
        $scale = $longest > self::MAX_SIDE ? self::MAX_SIDE / $longest : 1.0;

        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        if (! ImageMemoryGuard::fits($width * $height, $targetWidth * $targetHeight)) {
            throw new RuntimeException('Фотография слишком большая. Попробуйте снимок поменьше.');
        }

        $source = @imagecreatefromstring((string) file_get_contents($photo->getRealPath()));

        // GD не знает HEIC с айфонов. Приложение приводит снимок к JPEG само
        // (`toJpeg` в src/lib/photo.ts), с сайта такое приходит редко.
        if ($source === false) {
            throw new RuntimeException('Такой формат фотографии не поддерживается. Подойдут JPEG, PNG или WEBP.');
        }

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        // У JPEG нет прозрачности: без белой заливки PNG с альфой приезжает
        // с чёрными полями (на этом наступали с аватарами).
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
        imagedestroy($source);

        ob_start();
        imagejpeg($canvas, null, self::JPEG_QUALITY);
        $binary = (string) ob_get_clean();
        imagedestroy($canvas);

        return $binary;
    }
}
