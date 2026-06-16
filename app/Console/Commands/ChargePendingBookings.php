<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Services\BookingService;
use Illuminate\Console\Command;

class ChargePendingBookings extends Command
{
    protected $signature = 'studio:charge-pending-bookings';

    protected $description = 'Списать занятия по броням, созданным до начала действия абонемента';

    public function handle(BookingService $bookings): int
    {
        $pending = Booking::query()
            ->where('status', BookingStatus::Confirmed)
            ->whereNotNull('subscription_id')
            ->whereNull('subscription_usage_id')
            ->with(['subscription', 'classSession', 'user'])
            ->get()
            ->filter(fn (Booking $booking) => $booking->subscription?->hasStarted() ?? false);

        $charged = 0;

        foreach ($pending as $booking) {
            if ($bookings->chargePendingBooking($booking)) {
                $charged++;
            }
        }

        $this->info("Charged {$charged} pending booking(s).");

        return self::SUCCESS;
    }
}
