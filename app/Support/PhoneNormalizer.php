<?php

namespace App\Support;

class PhoneNormalizer
{
    /**
     * Нормализует телефон к 11 цифрам, начинающимся с 7 (Россия).
     */
    public static function normalize(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if ($digits === '') {
            return null;
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '8')) {
            $digits = '7'.substr($digits, 1);
        }

        if (strlen($digits) === 10) {
            $digits = '7'.$digits;
        }

        if (strlen($digits) !== 11 || ! str_starts_with($digits, '7')) {
            return null;
        }

        return $digits;
    }

    public static function format(?string $normalized): ?string
    {
        if ($normalized === null || strlen($normalized) !== 11) {
            return null;
        }

        return sprintf(
            '+7 (%s) %s-%s-%s',
            substr($normalized, 1, 3),
            substr($normalized, 4, 3),
            substr($normalized, 7, 2),
            substr($normalized, 9, 2),
        );
    }
}
