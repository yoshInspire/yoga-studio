<?php

namespace App\Filament\Resources\Bookings\Pages;

use App\Filament\Resources\Bookings\BookingResource;
use App\Models\ClassSession;
use App\Models\Subscription;
use App\Models\User;
use App\Services\BookingService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class CreateBooking extends CreateRecord
{
    protected static string $resource = BookingResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $subscription = ! empty($data['subscription_id'])
            ? Subscription::query()->findOrFail($data['subscription_id'])
            : null;

        try {
            return app(BookingService::class)->book(
                User::query()->findOrFail($data['user_id']),
                ClassSession::query()->findOrFail($data['class_session_id']),
                $subscription,
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'user_id' => $e->getMessage(),
            ]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
