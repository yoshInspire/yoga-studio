<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case WaitingForCapture = 'waiting_for_capture';
    case Succeeded = 'succeeded';
    case Canceled = 'canceled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Создан',
            self::WaitingForCapture => 'Ожидает подтверждения',
            self::Succeeded => 'Оплачен',
            self::Canceled => 'Отменён',
        };
    }

    public function isPaid(): bool
    {
        return $this === self::Succeeded;
    }
}
