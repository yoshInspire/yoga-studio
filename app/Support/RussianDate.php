<?php

namespace App\Support;

use Illuminate\Support\Carbon;

final class RussianDate
{
    /** @var list<string> */
    private const MONTHS_GENITIVE = [
        1 => 'января',
        2 => 'февраля',
        3 => 'марта',
        4 => 'апреля',
        5 => 'мая',
        6 => 'июня',
        7 => 'июля',
        8 => 'августа',
        9 => 'сентября',
        10 => 'октября',
        11 => 'ноября',
        12 => 'декабря',
    ];

    /** @var list<string> */
    private const WEEKDAYS_FULL = [
        1 => 'Понедельник',
        2 => 'Вторник',
        3 => 'Среда',
        4 => 'Четверг',
        5 => 'Пятница',
        6 => 'Суббота',
        7 => 'Воскресенье',
    ];

    public static function weekdayFull(Carbon $date): string
    {
        return self::WEEKDAYS_FULL[$date->dayOfWeekIso];
    }

    public static function weekdayHeader(Carbon $date): string
    {
        return self::weekdayFull($date).' '.$date->format('d.m');
    }

    /** @var list<string> */
    private const WEEKDAYS_SHORT = ['Вс', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб'];

    public static function dayMonth(Carbon $date): string
    {
        return $date->day.' '.self::MONTHS_GENITIVE[$date->month];
    }

    public static function dayMonthYear(Carbon $date): string
    {
        return self::dayMonth($date).' '.$date->year;
    }

    public static function weekdayShortDayMonth(Carbon $date): string
    {
        return self::WEEKDAYS_SHORT[$date->dayOfWeek].', '.self::dayMonth($date);
    }

    public static function dayMonthRange(Carbon $from, Carbon $to): string
    {
        return self::dayMonth($from).' – '.self::dayMonth($to);
    }
}
