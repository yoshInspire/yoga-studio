<?php

namespace App\Filament\Resources\ClassSessions\Pages;

use App\Enums\BookingStatus;
use App\Filament\Resources\ClassSessions\ClassSessionResource;
use App\Models\ClassSession;
use App\Services\BookingService;
use App\Services\NotificationService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditClassSession extends EditRecord
{
    protected static string $resource = ClassSessionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('cancelClass')
                ->label('Отменить занятие')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (ClassSession $record) => ! $record->isCancelled())
                ->form([
                    Textarea::make('reason')
                        ->label('Причина отмены')
                        ->required()
                        ->rows(3),
                ])
                ->action(function (array $data, ClassSession $record, BookingService $bookings, NotificationService $notifications): void {
                    $affected = $record->bookings()
                        ->where('status', BookingStatus::Confirmed)
                        ->with('user')
                        ->get();

                    $bookings->cancelClass($record, $data['reason']);

                    foreach ($affected as $booking) {
                        if ($booking->user === null) {
                            continue;
                        }

                        $notifications->notifyUser(
                            $booking->user,
                            'Занятие отменено',
                            [
                                'Здравствуйте, '.$booking->user->first_name.'!',
                                'Занятие «'.$record->title.'» '.$record->formattedDateTime().' отменено.',
                                'Причина: '.$data['reason'],
                                'Занятие возвращено на ваш абонемент.',
                            ],
                            subject: 'Занятие отменено',
                        );
                    }

                    Notification::make()
                        ->title('Занятие отменено')
                        ->body('Клиентам возвращены занятия и отправлены уведомления (почта/Telegram).')
                        ->success()
                        ->send();

                    $this->refreshFormData(['status', 'cancellation_reason', 'cancelled_at']);
                }),
            DeleteAction::make(),
        ];
    }
}
