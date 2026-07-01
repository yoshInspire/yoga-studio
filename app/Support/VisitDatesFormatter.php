<?php

namespace App\Support;

use App\Models\Subscription;
use Illuminate\Support\Carbon;

class VisitDatesFormatter
{
    public static function normalizeList(string $dates, Subscription $subscription): string
    {
        $parts = array_values(array_filter(array_map('trim', explode(',', $dates))));

        if ($parts === []) {
            return '';
        }

        return implode(', ', array_map(
            fn (string $part) => self::normalizeSingle($part, $subscription),
            $parts,
        ));
    }

    public static function normalizeSingle(string $date, Subscription $subscription): string
    {
        $date = trim($date);

        if (preg_match('/^\d{1,2}\.\d{1,2}\.\d{4}$/', $date)) {
            return Carbon::createFromFormat('d.m.Y', $date)->format('d.m.Y');
        }

        if (preg_match('/^(\d{1,2})\.(\d{1,2})$/', $date, $matches)) {
            $day = (int) $matches[1];
            $month = (int) $matches[2];
            $year = self::inferYear($month, $subscription);

            return sprintf('%02d.%02d.%d', $day, $month, $year);
        }

        return $date;
    }

    public static function normalizeAdminNote(string $note, Subscription $subscription): string
    {
        return (string) preg_replace_callback(
            '/Посещения:\s*(.+?)(?:\n|$)/u',
            fn (array $matches) => 'Посещения: '.self::normalizeList($matches[1], $subscription),
            $note,
            limit: 1,
        );
    }

    private static function inferYear(int $month, Subscription $subscription): int
    {
        $start = $subscription->starts_at;
        $end = $subscription->ends_at;

        if ($start->year === $end->year) {
            return $start->year;
        }

        return $month >= $start->month ? $start->year : $end->year;
    }
}
