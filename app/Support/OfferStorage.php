<?php

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Хранение договора-оферты (один PDF) в приватном диске.
 * Файл недоступен по прямой ссылке — отдаётся только через защищённый маршрут.
 */
class OfferStorage
{
    public const DISK = 'local';

    public const PATH = 'offer/contract.pdf';

    public static function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }

    public static function exists(): bool
    {
        return self::disk()->exists(self::PATH);
    }

    public static function absolutePath(): string
    {
        return self::disk()->path(self::PATH);
    }

    public static function updatedAt(): ?Carbon
    {
        if (! self::exists()) {
            return null;
        }

        return Carbon::createFromTimestamp(self::disk()->lastModified(self::PATH));
    }

    public static function delete(): void
    {
        if (self::exists()) {
            self::disk()->delete(self::PATH);
        }
    }
}
