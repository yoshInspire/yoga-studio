<?php

namespace Tests\Unit;

use App\Support\RussianDate;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class RussianDateTest extends TestCase
{
    public function test_day_month_year_uses_arabic_numerals(): void
    {
        $date = Carbon::parse('2026-06-02');

        $this->assertSame('2 июня 2026', RussianDate::dayMonthYear($date));
    }

    public function test_day_month_range(): void
    {
        $from = Carbon::parse('2026-06-08');
        $to = Carbon::parse('2026-06-14');

        $this->assertSame('8 июня – 14 июня', RussianDate::dayMonthRange($from, $to));
    }
}
