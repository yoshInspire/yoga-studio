<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'key',
    'body',
])]
class StudioText extends Model
{
    /** Приветствие при первом бронировании. */
    public const WELCOME_VISIT = 'welcome_visit';

    /** Текст администратора, а если его не задавали — значение по умолчанию. */
    public static function body(string $key, string $default = ''): string
    {
        $body = static::query()->where('key', $key)->value('body');

        return filled($body) ? $body : $default;
    }

    public static function put(string $key, string $body): void
    {
        static::query()->updateOrCreate(['key' => $key], ['body' => trim($body)]);
    }
}
