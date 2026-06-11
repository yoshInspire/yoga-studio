<?php

namespace App\Enums;

enum NewsReactionType: string
{
    case Like = 'like';
    case Love = 'love';
    case Fire = 'fire';
    case Thanks = 'thanks';

    public function emoji(): string
    {
        return match ($this) {
            self::Like => '👍',
            self::Love => '❤️',
            self::Fire => '🔥',
            self::Thanks => '🙏',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Like => 'Нравится',
            self::Love => 'Люблю',
            self::Fire => 'Огонь',
            self::Thanks => 'Спасибо',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
