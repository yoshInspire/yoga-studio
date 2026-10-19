<?php

namespace App\Filament\Resources\ClassSessions\Pages;

use App\Filament\Resources\ClassSessions\ClassSessionResource;
use App\Services\BookingService;
use Filament\Resources\Pages\CreateRecord;

class CreateClassSession extends CreateRecord
{
    protected static string $resource = ClassSessionResource::class;

    protected function afterCreate(): void
    {
        ClassSessionResource::notifyReservedBooking(
            app(BookingService::class)->bookReservedClients($this->getRecord()),
        );
    }
}
