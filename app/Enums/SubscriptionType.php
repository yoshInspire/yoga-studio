<?php

namespace App\Enums;

/**
 * Тип абонемента / занятия.
 * Не путать с направлением «Йога-нидра» — special_event = мероприятия вне обычного абонемента.
 */
enum SubscriptionType: string
{
    case Group = 'group';
    case Individual = 'individual';
    case SpecialEvent = 'special_event';

    public function label(): string
    {
        return match ($this) {
            self::Group => 'Групповые занятия',
            self::Individual => 'Индивидуальные занятия',
            self::SpecialEvent => 'Мероприятия вне абонемента',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::Group => 'Групповой',
            self::Individual => 'Индивидуальный',
            self::SpecialEvent => 'Вне абонемента',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Group => 'group',
            self::Individual => 'indiv',
            self::SpecialEvent => 'event',
        };
    }

    public function isCompatibleWith(self $other): bool
    {
        return $this === $other;
    }
}
