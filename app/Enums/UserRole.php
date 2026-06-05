<?php

namespace App\Enums;

enum UserRole: string
{
    case Client = 'client';
    case Trainer = 'trainer';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Client => 'Клиент',
            self::Trainer => 'Тренер',
            self::Admin => 'Администратор',
        };
    }
}
