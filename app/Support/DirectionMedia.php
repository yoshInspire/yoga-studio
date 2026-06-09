<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

class DirectionMedia
{
    public static function url(?string $ref, int $width = 900): string
    {
        if (! filled($ref)) {
            return '';
        }

        if (str_starts_with($ref, 'images/')) {
            return asset($ref);
        }

        if (! str_contains($ref, '://')) {
            return Storage::disk('public')->url($ref);
        }

        return "https://images.unsplash.com/{$ref}?auto=format&fit=crop&w={$width}&q=80";
    }
}
