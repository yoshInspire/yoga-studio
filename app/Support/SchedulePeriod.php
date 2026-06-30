<?php

namespace App\Support;

use Illuminate\Support\Carbon;

class SchedulePeriod
{
    public const CURRENT_WEEK = 'current_week';

    public const NEXT_WEEK = 'next_week';

    public const THIS_MONTH = 'this_month';

    public const ALL = 'all';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::CURRENT_WEEK => 'Эта неделя',
            self::NEXT_WEEK => 'Следующая неделя',
            self::THIS_MONTH => 'Этот месяц',
            self::ALL => 'Все даты',
        ];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}|null
     */
    public static function range(string $period, ?Carbon $on = null): ?array
    {
        $on ??= now();

        return match ($period) {
            self::CURRENT_WEEK => self::weekRange($on, 0),
            self::NEXT_WEEK => self::weekRange($on, 1),
            self::THIS_MONTH => [
                $on->copy()->startOfMonth()->startOfDay(),
                $on->copy()->endOfMonth()->endOfDay(),
            ],
            self::ALL => null,
            default => self::weekRange($on, 0),
        };
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private static function weekRange(Carbon $on, int $weekOffset): array
    {
        $start = $on->copy()->addWeeks($weekOffset)->startOfWeek(Carbon::MONDAY)->startOfDay();

        return [$start, $start->copy()->addDays(6)->endOfDay()];
    }
}
