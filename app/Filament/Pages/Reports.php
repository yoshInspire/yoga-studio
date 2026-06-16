<?php

namespace App\Filament\Pages;

use App\Enums\UserRole;
use App\Exports\BookingsAnalyticsExport;
use App\Exports\ClientStatsExport;
use App\Exports\SubscriptionsWorkbookExport;
use App\Exports\VisitsExport;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;

class Reports extends Page
{
    protected static ?string $navigationLabel = 'Отчёты';

    protected static ?string $title = 'Выгрузка отчётов';

    protected static ?int $navigationSort = 5;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentArrowDown;

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Абонементы')
                ->description('Три листа Excel: групповые, индивидуальные и мероприятия вне абонемента. Включая даты посещений по каждому абонементу.')
                ->schema([
                    Actions::make([
                        $this->downloadSubscriptionsAction(),
                    ]),
                ]),
            Section::make('Статистика по клиентам')
                ->description('Дата регистрации и количество посещений по месяцам. Можно выбрать одного клиента.')
                ->schema([
                    Actions::make([
                        $this->downloadClientStatsAction(),
                    ]),
                ]),
            Section::make('Аналитика записей')
                ->description('Занятия с датой, временем, списком записавшихся и количеством мест.')
                ->schema([
                    Actions::make([
                        $this->downloadBookingsAnalyticsAction(),
                    ]),
                ]),
            Section::make('Посещения')
                ->description('ФИО, телефон и даты завершённых посещений.')
                ->schema([
                    Actions::make([
                        $this->downloadVisitsAction(),
                    ]),
                ]),
        ]);
    }

    private function downloadSubscriptionsAction(): Action
    {
        return Action::make('subscriptions')
            ->label('Скачать Excel')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->action(fn () => Excel::download(
                new SubscriptionsWorkbookExport,
                'abonementy-'.now()->format('Y-m-d').'.xlsx',
            ));
    }

    private function downloadClientStatsAction(): Action
    {
        return Action::make('clientStats')
            ->label('Скачать Excel')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->schema([
                Select::make('user_id')
                    ->label('Клиент')
                    ->placeholder('Все клиенты')
                    ->searchable()
                    ->options(fn () => User::query()
                        ->where('role', UserRole::Client)
                        ->orderBy('last_name')
                        ->get()
                        ->mapWithKeys(fn (User $user) => [$user->id => $user->fullName()])
                        ->all()),
            ])
            ->action(function (array $data) {
                $clientId = filled($data['user_id'] ?? null) ? (int) $data['user_id'] : null;

                return Excel::download(
                    new ClientStatsExport($clientId),
                    'statistika-klientov-'.now()->format('Y-m-d').'.xlsx',
                );
            });
    }

    private function downloadBookingsAnalyticsAction(): Action
    {
        return Action::make('bookingsAnalytics')
            ->label('Скачать Excel')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->schema($this->periodSchema())
            ->action(function (array $data) {
                return Excel::download(
                    new BookingsAnalyticsExport(
                        $this->parseDate($data['from'] ?? null),
                        $this->parseDate($data['to'] ?? null, endOfDay: true),
                    ),
                    'zapisi-'.now()->format('Y-m-d').'.xlsx',
                );
            });
    }

    private function downloadVisitsAction(): Action
    {
        return Action::make('visits')
            ->label('Скачать Excel')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->schema([
                ...$this->periodSchema(),
                Select::make('user_id')
                    ->label('Клиент')
                    ->placeholder('Все клиенты')
                    ->searchable()
                    ->options(fn () => User::query()
                        ->where('role', UserRole::Client)
                        ->orderBy('last_name')
                        ->get()
                        ->mapWithKeys(fn (User $user) => [$user->id => $user->fullName()])
                        ->all()),
            ])
            ->action(function (array $data) {
                $clientId = filled($data['user_id'] ?? null) ? (int) $data['user_id'] : null;

                return Excel::download(
                    new VisitsExport(
                        $this->parseDate($data['from'] ?? null),
                        $this->parseDate($data['to'] ?? null, endOfDay: true),
                        $clientId,
                    ),
                    'poseshcheniya-'.now()->format('Y-m-d').'.xlsx',
                );
            });
    }

    /**
     * @return list<DatePicker>
     */
    private function periodSchema(): array
    {
        return [
            DatePicker::make('from')
                ->label('С даты')
                ->native(false)
                ->displayFormat('d.m.Y'),
            DatePicker::make('to')
                ->label('По дату')
                ->native(false)
                ->displayFormat('d.m.Y'),
        ];
    }

    private function parseDate(mixed $value, bool $endOfDay = false): ?Carbon
    {
        if (! filled($value)) {
            return null;
        }

        $date = Carbon::parse($value);

        return $endOfDay ? $date->endOfDay() : $date->startOfDay();
    }
}
