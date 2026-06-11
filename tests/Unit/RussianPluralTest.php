<?php

namespace Tests\Unit;

use App\Support\RussianPlural;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RussianPluralTest extends TestCase
{
    #[DataProvider('daysProvider')]
    public function test_days(int $count, string $expected): void
    {
        $this->assertSame($expected, RussianPlural::days($count));
    }

    public static function daysProvider(): array
    {
        return [
            [1, 'день'],
            [2, 'дня'],
            [4, 'дня'],
            [5, 'дней'],
            [11, 'дней'],
            [14, 'дней'],
            [21, 'день'],
            [30, 'дней'],
        ];
    }
}
