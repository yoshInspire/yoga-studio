<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Bookings\BookingResource;
use App\Models\Booking;
use App\Services\BookingService;
use App\Services\VisitControlService;
use App\Support\RussianDate;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class VisitControl extends Page
{
    public string $selectedDate = '';

    protected static ?string $navigationLabel = 'Посещения';

    protected static ?string $title = 'Контроль посещений';

    protected static ?int $navigationSort = 3;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected string $view = 'filament.pages.visit-control';

    public function mount(): void
    {
        $this->selectedDate = now()->toDateString();
    }

    public function getViewData(): array
    {
        $date = Carbon::parse($this->selectedDate)->startOfDay();
        $day = app(VisitControlService::class)->buildDay($date);

        return [
            'day' => $day,
            'prevDate' => $date->copy()->subDay()->toDateString(),
            'nextDate' => $date->copy()->addDay()->toDateString(),
            'todayDate' => now()->toDateString(),
            'yesterdayDate' => now()->subDay()->toDateString(),
            'tomorrowDate' => now()->addDay()->toDateString(),
            'createBookingBaseUrl' => BookingResource::getUrl('create'),
        ];
    }

    public function goToDate(string $date): void
    {
        $this->selectedDate = Carbon::parse($date)->toDateString();
    }

    public function markAttended(int $bookingId): void
    {
        $this->runBookingAction($bookingId, fn (Booking $booking) => app(BookingService::class)->markAttended($booking), 'Присутствие отмечено');
    }

    public function markNoShow(int $bookingId): void
    {
        $this->runBookingAction(
            $bookingId,
            fn (Booking $booking) => app(BookingService::class)->markNoShow($booking),
            'Неявка: занятие возвращено на абонемент',
        );
    }

    public function cancelBooking(int $bookingId): void
    {
        $this->runBookingAction(
            $bookingId,
            fn (Booking $booking) => app(BookingService::class)->cancelByAdmin($booking, 'Отменено в контроле посещений'),
            'Запись отменена, занятие возвращено на абонемент',
        );
    }

    private function runBookingAction(int $bookingId, callable $action, string $successTitle): void
    {
        try {
            $action(Booking::query()->findOrFail($bookingId));

            Notification::make()
                ->title($successTitle)
                ->success()
                ->send();
        } catch (InvalidArgumentException $e) {
            Notification::make()
                ->title('Не удалось выполнить действие')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function datePickerLabel(): string
    {
        $date = Carbon::parse($this->selectedDate);

        return RussianDate::dayMonthYear($date).' · '.mb_ucfirst($date->translatedFormat('l'));
    }
}
