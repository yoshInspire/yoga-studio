<?php

namespace App\Filament\Resources\Bookings\Pages;

use App\Filament\Resources\Bookings\BookingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBookings extends ListRecords
{
    protected static string $resource = BookingResource::class;

    protected ?string $subheading = 'Записи сгруппированы по занятиям — нажмите на строку занятия, чтобы увидеть список клиентов. Обзор по дням: раздел «Занятия».';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
