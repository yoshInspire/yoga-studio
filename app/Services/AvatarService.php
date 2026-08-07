<?php

namespace App\Services;

use App\Models\User;
use App\Support\ImageMemoryGuard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Фотография пользователя (аватар).
 *
 * Аватар везде показывается кружком небольшого размера, поэтому храним квадрат
 * со стороной 512 px: этого хватает и на шапку приложения, и на retina-экран,
 * а снимок с телефона на 4000 px занимал бы место впустую.
 *
 * Диск — публичный (`storage/app/public`, наружу через симлинк `/storage`).
 * Так картинка отдаётся nginx-ом напрямую и кэшируется и браузером, и
 * `expo-image` в приложении. Имя файла случайное (ULID), ссылку не подобрать.
 * Вложения переписки, в отличие от аватара, лежат в приватном диске: там
 * содержимое куда чувствительнее, чем портрет в кружке.
 */
class AvatarService
{
    /** Сторона квадрата, в котором храним аватар. */
    public const SIZE = 512;

    private const JPEG_QUALITY = 88;

    private const DISK = 'public';

    private const DIRECTORY = 'avatars';

    /**
     * Поставить новую фотографию; прежняя удаляется.
     *
     * @throws RuntimeException если картинку не удалось прочитать
     */
    public function update(User $user, UploadedFile $photo): User
    {
        $previous = $user->avatar_path;

        $user->forceFill(['avatar_path' => $this->store($photo)])->save();

        $this->deleteFile($previous);

        return $user->refresh();
    }

    /** Убрать фотографию — вернётся кружок с инициалами. */
    public function remove(User $user): User
    {
        $previous = $user->avatar_path;

        if ($previous === null) {
            return $user;
        }

        $user->forceFill(['avatar_path' => null])->save();

        $this->deleteFile($previous);

        return $user->refresh();
    }

    /**
     * Сохранить снимок квадратом.
     *
     * @return string путь на диске
     */
    private function store(UploadedFile $photo): string
    {
        $binary = $this->square($photo);
        $path = self::DIRECTORY.'/'.Str::ulid().'.jpg';

        Storage::disk(self::DISK)->put($path, $binary);

        return $path;
    }

    /**
     * Обрезать по центру до квадрата и ужать до SIZE.
     *
     * Обрезаем именно здесь, а не только в интерфейсе: кружок на клиенте всё
     * равно покажет центр картинки, а на диске иначе остался бы полный кадр.
     */
    private function square(UploadedFile $photo): string
    {
        if (! function_exists('imagecreatefromstring')) {
            throw new RuntimeException('На сервере нет расширения GD — обработка фотографий недоступна.');
        }

        $dimensions = ImageMemoryGuard::dimensions($photo->getRealPath());

        if ($dimensions === null) {
            throw new RuntimeException('Не удалось прочитать фотографию.');
        }

        [$width, $height] = $dimensions;
        $side = min(self::SIZE, min($width, $height));

        if (! ImageMemoryGuard::fits($width * $height, $side * $side)) {
            throw new RuntimeException('Фотография слишком большая. Попробуйте снимок поменьше.');
        }

        $source = @imagecreatefromstring((string) file_get_contents($photo->getRealPath()));

        // GD не знает HEIC с айфонов. В переписке такой файл кладётся как есть,
        // но аватар обязан быть квадратным JPEG — иначе кружок поедет, — поэтому
        // здесь честно говорим, что формат не подошёл. Приложение снимок
        // конвертирует само, с сайта такое приходит редко.
        if ($source === false) {
            throw new RuntimeException('Такой формат фотографии не поддерживается. Подойдут JPEG, PNG или WEBP.');
        }

        // Квадрат по центру: у портрета срезаются поля сверху и снизу,
        // у пейзажа — по бокам.
        $cropSide = min($width, $height);
        $srcX = (int) round(($width - $cropSide) / 2);
        $srcY = (int) round(($height - $cropSide) / 2);

        $canvas = imagecreatetruecolor($side, $side);
        // Белая подложка: у JPEG нет прозрачности, иначе PNG с альфой
        // приезжает с чёрными полями.
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, $srcX, $srcY, $side, $side, $cropSide, $cropSide);
        imagedestroy($source);

        ob_start();
        imagejpeg($canvas, null, self::JPEG_QUALITY);
        $binary = (string) ob_get_clean();
        imagedestroy($canvas);

        return $binary;
    }

    private function deleteFile(?string $path): void
    {
        if ($path === null) {
            return;
        }

        Storage::disk(self::DISK)->delete($path);
    }
}
