<?php

namespace App\Exports\Sheets;

use App\Enums\SubscriptionType;
use App\Services\ReportService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class SubscriptionTypeSheet implements FromCollection, ShouldAutoSize, WithHeadings, WithTitle
{
    public function __construct(
        private SubscriptionType $type,
        private ReportService $reports,
    ) {}

    public function title(): string
    {
        return match ($this->type) {
            SubscriptionType::Group => 'Групповые',
            SubscriptionType::Individual => 'Индивидуальные',
            SubscriptionType::SpecialEvent => 'Вне абонемента',
        };
    }

    public function headings(): array
    {
        return [
            'Фамилия',
            'Имя',
            'Отчество',
            'Телефон',
            'Всего занятий',
            'Использовано',
            'Остаток',
            'Дата покупки',
            'Начало действия',
            'Окончание',
            'Даты посещений',
            'Примечание',
        ];
    }

    public function collection(): Collection
    {
        return $this->reports
            ->subscriptionsByType($this->type)
            ->map(fn ($subscription) => [
                $subscription->user->last_name,
                $subscription->user->first_name,
                $subscription->user->patronymic ?? '',
                $subscription->user->formattedPhone() ?? $subscription->user->phone ?? '',
                $subscription->sessions_total,
                $this->reports->completedSessionsUsed($subscription),
                $this->reports->sessionsRemainingAsOf($subscription),
                $subscription->purchased_at->format('d.m.Y'),
                $subscription->starts_at->format('d.m.Y'),
                $subscription->ends_at->format('d.m.Y'),
                $this->reports->visitDatesForSubscription($subscription),
                $subscription->admin_note ?? '',
            ]);
    }
}
