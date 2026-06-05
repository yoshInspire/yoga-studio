<?php

namespace App\Exports;

use App\Services\ReportService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class VisitsExport implements FromCollection, ShouldAutoSize, WithHeadings
{
    public function __construct(
        private ?Carbon $from = null,
        private ?Carbon $to = null,
        private ?int $clientId = null,
        private ?ReportService $reports = null,
    ) {
        $this->reports ??= app(ReportService::class);
    }

    public function headings(): array
    {
        return [
            'Дата посещения',
            'Время',
            'Фамилия',
            'Имя',
            'Отчество',
            'Телефон',
            'Занятие',
            'Тип занятия',
        ];
    }

    public function collection(): Collection
    {
        return $this->reports
            ->completedVisits($this->from, $this->to, $this->clientId)
            ->map(fn ($booking) => [
                $booking->classSession->starts_at->format('d.m.Y'),
                $booking->classSession->starts_at->format('H:i'),
                $booking->user->last_name,
                $booking->user->first_name,
                $booking->user->patronymic ?? '',
                $booking->user->formattedPhone() ?? $booking->user->phone ?? '',
                $booking->classSession->title,
                $booking->classSession->type->label(),
            ]);
    }
}
