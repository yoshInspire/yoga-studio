<?php

namespace App\Enums;

enum AttendanceStatus: string
{
    case Expected = 'expected';
    case Attended = 'attended';
    case NoShow = 'no_show';

    public function label(): string
    {
        return match ($this) {
            self::Expected => 'Ожидается',
            self::Attended => 'Был(а)',
            self::NoShow => 'Не пришёл(ла)',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Expected => 'gray',
            self::Attended => 'success',
            self::NoShow => 'danger',
        };
    }
}
