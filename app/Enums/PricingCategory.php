<?php

namespace App\Enums;

enum PricingCategory: string
{
    case Group = 'group';
    case Individual = 'individual';

    public function label(): string
    {
        return match ($this) {
            self::Group => 'Групповые занятия',
            self::Individual => 'Индивидуальные занятия',
        };
    }
}
