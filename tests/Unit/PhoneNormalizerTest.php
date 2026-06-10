<?php

namespace Tests\Unit;

use App\Support\PhoneNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PhoneNormalizerTest extends TestCase
{
    #[DataProvider('normalizeProvider')]
    public function test_normalize_accepts_common_russian_formats(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, PhoneNormalizer::normalize($input));
    }

    public static function normalizeProvider(): array
    {
        return [
            ['+7 (999) 555-66-66', '79995556666'],
            ['79995556666', '79995556666'],
            ['89995556666', '79995556666'],
            ['8 (999) 555-66-66', '79995556666'],
            ['', null],
            ['123', null],
        ];
    }

    #[DataProvider('formatInputProvider')]
    public function test_format_input_formats_while_typing(string $input, string $expected): void
    {
        $this->assertSame($expected, PhoneNormalizer::formatInput($input));
    }

    public static function formatInputProvider(): array
    {
        return [
            ['89995556666', '+7 (999) 555-66-66'],
            ['79995556666', '+7 (999) 555-66-66'],
            ['9995556666', '+7 (999) 555-66-66'],
            ['8999', '+7 (999'],
            ['', ''],
        ];
    }
}
