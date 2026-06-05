<?php

namespace App\Enums;

enum BookingStatus: string
{
    case Confirmed = 'confirmed';
    case CancelledByClient = 'cancelled_by_client';
    case CancelledByAdmin = 'cancelled_by_admin';
    case ClassCancelled = 'class_cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Confirmed => 'Подтверждена',
            self::CancelledByClient => 'Отменена клиентом',
            self::CancelledByAdmin => 'Отменена администратором',
            self::ClassCancelled => 'Занятие отменено',
        };
    }

    public function isActive(): bool
    {
        return $this === self::Confirmed;
    }
}
