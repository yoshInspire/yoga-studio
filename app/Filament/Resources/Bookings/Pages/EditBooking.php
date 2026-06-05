<?php

namespace App\Filament\Resources\Bookings\Pages;

use App\Filament\Resources\Bookings\BookingResource;
use App\Models\Booking;
use App\Services\BookingService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditBooking extends EditRecord
{
    protected static string $resource = BookingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('cancelBooking')
                ->label('Отменить запись')
                ->icon('heroicon-o-x-mark')
                ->color('danger')
                ->visible(fn (Booking $record) => $record->isConfirmed())
                ->form([
                    Textarea::make('reason')
                        ->label('Причина')
                        ->rows(2),
                ])
                ->action(function (array $data, Booking $record, BookingService $bookings): void {
                    $bookings->cancelByAdmin($record, $data['reason'] ?? null);

                    Notification::make()
                        ->title('Запись отменена')
                        ->body('Занятие возвращено на абонемент клиента.')
                        ->success()
                        ->send();

                    $this->refreshFormData(['status', 'cancellation_reason', 'cancelled_at']);
                }),
            DeleteAction::make(),
        ];
    }
}
