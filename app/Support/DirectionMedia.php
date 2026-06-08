<?php

namespace App\Support;

class DirectionMedia
{
    public static function url(string $ref, int $width = 900): string
    {
        if (str_starts_with($ref, 'images/')) {
            return asset($ref);
        }

        return "https://images.unsplash.com/{$ref}?auto=format&fit=crop&w={$width}&q=80";
    }
}
