<?php

namespace App\Support;

use App\Models\AsanaProgram;
use App\Models\AsanaProgramItem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

/**
 * Лист занятия для печати — готовый HTML, который телефон отдаёт `expo-print`.
 *
 * Раскладку (колонки, размер картинки, кегль подписи) считает та же
 * `AsanaPrintLayout`, что и печать из веб-админки: печатать одно и то же
 * занятие с компьютера и с телефона должно означать одинаковый лист.
 * Отличается только оболочка: в вебе это `@media print` поверх живой
 * страницы, здесь — самостоятельный документ.
 *
 * **Картинки вшиваются в HTML целиком (data-URL), а не ссылками.** Ссылку
 * пришлось бы тянуть по сети уже внутри печатного вебвью, и печать началась бы
 * раньше, чем позы догрузятся, — на листе остались бы пустые клетки. Файлы
 * поз неизменяемы (библиотека приезжает с кодом, зарисовка каждый раз получает
 * новое имя), поэтому кодировку кэшируем по пути навсегда.
 */
final class AsanaPrintDocument
{
    /**
     * Длинная сторона, до которой ужимаем картинку перед вшиванием.
     *
     * Позы в библиотеке мелкие (порядка 150×100), зарисовки с холста — 600×450,
     * так что предел срабатывает редко. Он на случай, если в библиотеку
     * когда-нибудь попадёт большой файл: лист на 30 поз не должен превращаться
     * в мегабайты, которые телефон будет качать по мобильной сети.
     */
    private const MAX_SIDE = 900;

    /**
     * @return array{html: string, layout: array<string, mixed>, items: int}
     */
    public static function render(AsanaProgram $program, int $targetPages = 0): array
    {
        $items = $program->items()->with('asana')->get();
        $layout = AsanaPrintLayout::forItems($items, $targetPages);

        $cells = $items->values()->map(fn (AsanaProgramItem $item, int $index): array => [
            'number' => $index + 1,
            'title' => $item->title(),
            'note' => $item->note,
            'wide' => $item->isWideImage(),
            'image' => self::inlineImage($item->effectiveImagePath()),
        ])->all();

        $html = view('print.asana-program', [
            'program' => $program,
            'cells' => $cells,
            'layout' => $layout,
        ])->render();

        return [
            'html' => $html,
            'layout' => $layout,
            'items' => $items->count(),
        ];
    }

    /**
     * Картинка позы как data-URL. null — файла нет: в клетке останется
     * только подпись, это лучше битой картинки.
     */
    public static function inlineImage(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        return Cache::rememberForever('asana-print-img:'.$path, function () use ($path): ?string {
            $absolute = public_path($path);

            if (! is_file($absolute)) {
                return null;
            }

            $binary = self::shrink($absolute) ?? File::get($absolute);

            return 'data:image/png;base64,'.base64_encode($binary);
        });
    }

    /**
     * Уменьшить картинку, если она заметно крупнее печатной клетки.
     *
     * Возвращает null, когда уменьшать нечего или GD не справился, — тогда
     * вызывающий берёт файл как есть.
     */
    private static function shrink(string $absolute): ?string
    {
        $size = ImageMemoryGuard::dimensions($absolute);

        if ($size === null) {
            return null;
        }

        [$width, $height] = $size;
        $side = max($width, $height);

        if ($side <= self::MAX_SIDE || ! function_exists('imagecreatetruecolor')) {
            return null;
        }

        $scale = self::MAX_SIDE / $side;
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        if (! ImageMemoryGuard::fits($width * $height, $targetWidth * $targetHeight)) {
            return null;
        }

        $source = @imagecreatefromstring(File::get($absolute));

        if ($source === false) {
            return null;
        }

        $target = imagecreatetruecolor($targetWidth, $targetHeight);

        // Позы рисуются линиями по прозрачному или белому фону — без этого
        // прозрачность стала бы чёрной заливкой.
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        imagepng($target, null, 9);
        $binary = (string) ob_get_clean();

        imagedestroy($source);
        imagedestroy($target);

        return $binary === '' ? null : $binary;
    }
}
