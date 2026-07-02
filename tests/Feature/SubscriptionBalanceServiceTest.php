<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\BookingStatus;
use App\Enums\ClassSessionStatus;
use App\Enums\SubscriptionType;
use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Subscription;
use App\Models\User;
use App\Services\BookingService;
use App\Services\SubscriptionBalanceService;
use App\Services\VisitControlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionBalanceServiceTest extends TestCase
{
    use RefreshDatabase;

    private function client(): User
    {
        return User::create([
            'first_name' => 'Иван',
            'last_name' => 'Петров',
            'phone' => '+79990000001',
            'email' => 'client@example.com',
            'role' => UserRole::Client,
            'password' => 'secret123',
            'offer_accepted_at' => now(),
        ]);
    }

    private function subscription(User $user, array $overrides = []): Subscription
    {
        return Subscription::create(array_merge([
            'user_id' => $user->id,
            'type' => SubscriptionType::Group,
            'sessions_total' => 6,
            'sessions_used' => 0,
            'purchased_at' => now(),
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
        ], $overrides));
    }

    private function classSession(array $overrides = []): ClassSession
    {
        return ClassSession::create(array_merge([
            'topic' => 'Хатха-йога',
            'starts_at' => now()->startOfDay()->addHours(14),
            'type' => SubscriptionType::Group,
            'capacity' => 6,
            'status' => ClassSessionStatus::Scheduled,
        ], $overrides));
    }

    public function test_reserved_counts_charged_booking_awaiting_visit(): void
    {
        $user = $this->client();
        $sub = $this->subscription($user);
        $session = $this->classSession();

        app(BookingService::class)->bookForAdmin($user, $session);

        $balance = app(SubscriptionBalanceService::class)->breakdown($sub->fresh());

        $this->assertSame(6, $balance['sessions_total']);
        $this->assertSame(1, $balance['sessions_reserved']);
        $this->assertSame(0, $balance['sessions_consumed']);
        $this->assertSame(5, $balance['sessions_remaining']);
    }

    public function test_consumed_increases_after_visit_marked_attended(): void
    {
        $user = $this->client();
        $sub = $this->subscription($user);
        $session = $this->classSession();
        $booking = app(BookingService::class)->bookForAdmin($user, $session);

        app(BookingService::class)->markAttended($booking);

        $balance = app(SubscriptionBalanceService::class)->breakdown($sub->fresh());

        $this->assertSame(0, $balance['sessions_reserved']);
        $this->assertSame(1, $balance['sessions_consumed']);
        $this->assertSame(5, $balance['sessions_remaining']);
    }

    public function test_reserved_counts_multiple_future_bookings(): void
    {
        $user = $this->client();
        $sub = $this->subscription($user);

        app(BookingService::class)->bookForAdmin($user, $this->classSession([
            'starts_at' => now()->addDay()->setTime(10, 0),
        ]));
        app(BookingService::class)->bookForAdmin($user, $this->classSession([
            'starts_at' => now()->addDays(2)->setTime(10, 0),
        ]));

        $balance = app(SubscriptionBalanceService::class)->breakdown($sub->fresh());

        $this->assertSame(2, $balance['sessions_reserved']);
        $this->assertSame(0, $balance['sessions_consumed']);
        $this->assertSame(4, $balance['sessions_remaining']);
    }

    public function test_double_subscription_same_day_counts_two_reserved_sessions(): void
    {
        $user = $this->client();
        $sub = $this->subscription($user, ['sessions_total' => 2, 'sessions_per_day' => 2]);
        $day = now()->startOfDay();

        app(BookingService::class)->bookForAdmin($user, $this->classSession([
            'starts_at' => $day->copy()->setTime(10, 0),
        ]));
        app(BookingService::class)->bookForAdmin($user, $this->classSession([
            'starts_at' => $day->copy()->setTime(18, 0),
        ]));

        $balance = app(SubscriptionBalanceService::class)->breakdown($sub->fresh());

        $this->assertSame(2, $balance['sessions_reserved']);
        $this->assertSame(0, $balance['sessions_consumed']);
        $this->assertSame(0, $balance['sessions_remaining']);
    }

    public function test_pending_charge_booking_counts_as_reserved(): void
    {
        $user = $this->client();
        $classDay = now()->addDay()->startOfDay();
        $sub = $this->subscription($user, [
            'sessions_total' => 1,
            'starts_at' => $classDay,
        ]);
        $session = $this->classSession([
            'starts_at' => $classDay->copy()->setTime(18, 0),
        ]);

        app(BookingService::class)->bookForAdmin($user, $session, $sub);

        $balance = app(SubscriptionBalanceService::class)->breakdown($sub->fresh());

        $this->assertSame(1, $balance['sessions_reserved']);
        $this->assertSame(0, $balance['sessions_consumed']);
        $this->assertSame(1, $balance['sessions_remaining']);
    }
}
