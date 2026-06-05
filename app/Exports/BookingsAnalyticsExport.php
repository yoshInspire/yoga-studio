<?php

namespace App\Exports;

use App\Services\ReportService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class BookingsAnalyticsExport implements FromCollection, ShouldAutoSize, WithHeadings
{
    public function __construct(
        private ?Carbon $from = null,
        private ?Carbon $to = null,
        private ?ReportService $reports = null,
    ) {
        $this->reports ??= app(ReportService::class);
    }

    public function headings(): array
    {
        return [
            'Дата',
            'Время',
            'Занятие',
            'Тип',
            'Тренер',
            'Записано',
            'Вместимость',
            'Список гостей',
            'Статус занятия',
        ];
    }

    public function collection(): Collection
    {
        return $this->reports
            ->sessionsForAnalytics($this->from, $this->to)
            ->map(function ($session) {
                $attendees = $session->bookings
                    ->map(fn ($booking) => trim(implode(' ', array_filter([
                        $booking->user->last_name,
                        $booking->user->first_name,
                        $booking->user->patronymic,
                    ]))))
                    ->implode('; ');

                return [
                    $session->starts_at->format('d.m.Y'),
                    $session->starts_at->format('H:i'),
                    $session->title,
                    $session->type->label(),
                    $session->trainerName(),
                    $session->confirmed_count,
                    $session->capacity,
                    $attendees,
                    $session->isCancelled() ? 'Отменено' : 'Запланировано',
                ];
            });
    }
}
