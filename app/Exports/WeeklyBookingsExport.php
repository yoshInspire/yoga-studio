<?php

namespace App\Exports;

use App\Services\ReportService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class WeeklyBookingsExport implements FromCollection, ShouldAutoSize, WithHeadings, WithTitle
{
    /** @var array{headers: list<string>, columns: list<list<string>>, week_label: string} */
    private array $grid;

    public function __construct(
        private Carbon $weekStart,
        ?ReportService $reports = null,
    ) {
        $reports ??= app(ReportService::class);
        $this->grid = $reports->buildWeeklyBookingsGrid($weekStart);
    }

    public function title(): string
    {
        return 'Записи на неделю';
    }

    public function headings(): array
    {
        return $this->grid['headers'];
    }

    public function collection(): Collection
    {
        $columns = $this->grid['columns'];
        $maxRows = (int) collect($columns)->map(fn (array $lines) => count($lines))->max();

        if ($maxRows === 0) {
            return collect();
        }

        $rows = collect();

        for ($row = 0; $row < $maxRows; $row++) {
            $rows->push(collect($columns)->map(fn (array $lines) => $lines[$row] ?? '')->all());
        }

        return $rows;
    }
}
