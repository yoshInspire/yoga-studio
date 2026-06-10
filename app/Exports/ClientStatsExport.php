<?php

namespace App\Exports;

use App\Services\ReportService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ClientStatsExport implements FromCollection, ShouldAutoSize, WithHeadings
{
    /**
     * @param  list<string>  $months
     */
    public function __construct(
        private ?int $clientId = null,
        private ?ReportService $reports = null,
        private array $months = [],
    ) {
        $this->reports ??= app(ReportService::class);
        $this->months = $months ?: $this->reports->visitMonths();
    }

    public function headings(): array
    {
        return array_merge(
            ['Фамилия', 'Имя', 'Отчество', 'Телефон', 'Дата регистрации', 'Оферта подтверждена', 'Дата подтверждения оферты'],
            $this->months,
            ['Всего посещений'],
        );
    }

    public function collection(): Collection
    {
        return $this->reports
            ->clientsForStats($this->clientId)
            ->map(function ($client) {
                $monthCounts = [];
                $total = 0;

                foreach ($this->months as $month) {
                    $count = $this->reports->visitsCountForClientInMonth($client, $month);
                    $monthCounts[] = $count;
                    $total += $count;
                }

                return array_merge(
                    [
                        $client->last_name,
                        $client->first_name,
                        $client->patronymic ?? '',
                        $client->formattedPhone() ?? $client->phone ?? '',
                        $client->created_at->format('d.m.Y'),
                        $client->hasAcceptedOffer() ? 'Да' : 'Нет',
                        $client->formattedOfferAcceptedAt() ?? '',
                    ],
                    $monthCounts,
                    [$total],
                );
            });
    }
}
