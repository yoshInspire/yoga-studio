<?php

namespace App\Filament\Resources\Bookings\Pages;

use App\Filament\Pages\VisitControl;
use App\Filament\Resources\Bookings\BookingResource;
use App\Models\ClassSession;
use App\Models\Subscription;
use App\Models\User;
use App\Services\BookingService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class CreateBooking extends CreateRecord
{
    protected static string $resource = BookingResource::class;

    public function mount(): void
    {
        parent::mount();

        $sessionId = request()->integer('class_session_id');

        if ($sessionId > 0) {
            $this->form->fill(['class_session_id' => $sessionId]);
        }
    }

    protected function handleRecordCreation(array $data): Model
    {
        $subscription = ! empty($data['subscription_id'])
            ? Subscription::query()->findOrFail($data['subscription_id'])
            : null;

        try {
            return app(BookingService::class)->bookForAdmin(
                User::query()->findOrFail($data['user_id']),
                ClassSession::query()->findOrFail($data['class_session_id']),
                $subscription,
            );
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
            $field = $this->validationFieldForMessage($message);

            Notification::make()
                ->danger()
                ->title('Не удалось создать запись')
                ->body($message)
                ->persistent()
                ->send();

            throw ValidationException::withMessages([
                $field => $message,
            ]);
        }
    }

    private function validationFieldForMessage(string $message): string
    {
        if (str_contains($message, 'абонемент') || str_contains($message, 'Абонемент')) {
            return 'subscription_id';
        }

        if (str_contains($message, 'заняти') || str_contains($message, 'Заняти')) {
            return 'class_session_id';
        }

        return 'user_id';
    }

    protected function getRedirectUrl(): string
    {
        if (request()->has('class_session_id')) {
            return VisitControl::getUrl();
        }

        return $this->getResource()::getUrl('index');
    }
}
