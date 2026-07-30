<?php

namespace App\Support;

final class RussianPlural
{
    public static function days(int $count): string
    {
        return self::form($count, 'день', 'дня', 'дней');
    }

    public static function sessions(int $count): string
    {
        return self::form($count, 'занятие', 'занятия', 'занятий');
    }

    public static function poses(int $count): string
    {
        return self::form($count, 'поза', 'позы', 'поз');
    }

    private static function form(int $count, string $one, string $few, string $many): string
    {
        $mod10 = $count % 10;
        $mod100 = $count % 100;

        if ($mod100 >= 11 && $mod100 <= 14) {
            return $many;
        }

        return match ($mod10) {
            1 => $one,
            2, 3, 4 => $few,
            default => $many,
        };
    }
}
