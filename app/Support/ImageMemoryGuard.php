<?php

namespace App\Support;

/**
 * Влезет ли обработка картинки в память процесса.
 *
 * GD держит изображение по 4 байта на пиксель, и оригинал вместе с уменьшенной
 * копией какое-то время лежат в памяти одновременно. Снимок с современного
 * телефона разворачивается в десятки мегабайт, и процесс падает раньше, чем
 * успевает что-то отдать. Поэтому считаем заранее, по заголовку файла:
 * разворачивать снимок, чтобы узнать, что он не помещается, уже поздно.
 *
 * Общее для переписки (ChatService) и аватаров (AvatarService).
 */
final class ImageMemoryGuard
{
    /**
     * Размеры картинки по заголовку файла, без её распаковки.
     *
     * @return array{0: int, 1: int}|null null — файл не картинка либо формат незнаком
     */
    public static function dimensions(string $path): ?array
    {
        $size = @getimagesize($path);

        if ($size === false) {
            return null;
        }

        return [(int) $size[0], (int) $size[1]];
    }

    /**
     * Хватит ли памяти на оригинал в $sourcePixels пикселей и результат
     * в $outputPixels пикселей.
     */
    public static function fits(int $sourcePixels, int $outputPixels): bool
    {
        $needed = ($sourcePixels + $outputPixels) * 4;
        $limit = self::limitBytes();

        // Без ограничения памяти считаем, что места достаточно.
        if ($limit <= 0) {
            return true;
        }

        // Половина оставшегося — запас на всё остальное в запросе.
        return $needed < ($limit - memory_get_usage(true)) / 2;
    }

    /** memory_limit в байтах; -1 (без ограничения) отдаём как есть. */
    public static function limitBytes(): int
    {
        $raw = trim((string) ini_get('memory_limit'));

        if ($raw === '' || $raw === '-1') {
            return -1;
        }

        $value = (int) $raw;

        return match (strtolower(substr($raw, -1))) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }
}
