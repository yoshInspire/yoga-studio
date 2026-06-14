<?php

namespace App\Filament\Resources\Bookings\Pages;

use App\Filament\Resources\Bookings\BookingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBookings extends ListRecords
{
    protected static string $resource = BookingResource::class;

    protected ?string $subheading = 'Записи сгруппированы по занятиям — раскройте строку, чтобы увидеть клиентов. Индивидуальные занятия отмечены типом «Индивидуальный»; для быстрого поиска используйте фильтр «Тип занятия». Обзор слотов по дням — в разделе «Расписание».';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
