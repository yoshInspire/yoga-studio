<?php

namespace Tests\Unit;

use App\Support\SchedulePeriod;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SchedulePeriodTest extends TestCase
{
    #[Test]
    public function test_current_week_starts_on_monday(): void
    {
        $on = Carbon::parse('2026-06-29 15:00:00', 'Europe/Moscow');

        [$from, $to] = SchedulePeriod::range(SchedulePeriod::CURRENT_WEEK, $on);

        $this->assertSame('2026-06-29', $from->toDateString());
        $this->assertSame('2026-07-05', $to->toDateString());
    }

    #[Test]
    public function test_all_period_returns_null_range(): void
    {
        $this->assertNull(SchedulePeriod::range(SchedulePeriod::ALL));
    }
}
