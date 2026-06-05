<?php

namespace App\Exports;

use App\Enums\SubscriptionType;
use App\Exports\Sheets\SubscriptionTypeSheet;
use App\Services\ReportService;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class SubscriptionsWorkbookExport implements WithMultipleSheets
{
    use Exportable;

    public function __construct(
        private ?ReportService $reports = null,
    ) {
        $this->reports ??= app(ReportService::class);
    }

    public function sheets(): array
    {
        return [
            new SubscriptionTypeSheet(SubscriptionType::Group, $this->reports),
            new SubscriptionTypeSheet(SubscriptionType::Individual, $this->reports),
            new SubscriptionTypeSheet(SubscriptionType::SpecialEvent, $this->reports),
        ];
    }
}
