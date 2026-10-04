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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Записи, сделанные до начала действия абонемента, и их списание.
 *
 * Случай студии (Фадеева, сентябрь 2026): два разовых абонемента куплены
 * заранее, на оба занятия записались сразу — и обе записи ушли на первый.
 * В день начала списали первую, вторая навсегда осталась «Без списания»,
 * а второй абонемент потом ушёл на новую запись.
 */
class PendingChargeTest extends TestCase
{
    use RefreshDatabase;

    private BookingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(BookingService::class);
    }

    private function client(): User
    {
        return User::create([
            'first_name' => 'Анна',
            'last_name' => 'Фадеева',
            'phone' => '+79990000011',
            'role' => UserRole::Client,
            'password' => 'secret123',
        ]);
    }

    private function single(User $user, Carbon $startsAt): Subscription
    {
        return Subscription::create([
            'user_id' => $user->id,
            'type' => SubscriptionType::Individual,
            'sessions_total' => 1,
            'sessions_used' => 0,
            'purchased_at' => now(),
            'starts_at' => $startsAt->copy()->startOfDay(),
            'ends_at' => $startsAt->copy()->addDays(29),
        ]);
    }

    private function classAt(Carbon $startsAt): ClassSession
    {
        return ClassSession::create([
            'topic' => 'Здоровая спина',
            'starts_at' => $startsAt,
            'type' => SubscriptionType::Individual,
            'capacity' => 1,
            'status' => ClassSessionStatus::Scheduled,
        ]);
    }

    public function test_early_bookings_go_to_different_single_subscriptions(): void
    {
        $user = $this->client();
        $first = $this->single($user, now()->addDay());
        $second = $this->single($user, now()->addDays(3));

        $a = $this->service->bookForAdmin($user, $this->classAt(now()->addDay()->setTime(13, 0)));
        $b = $this->service->bookForAdmin($user, $this->classAt(now()->addDays(3)->setTime(16, 45)));

        $this->assertSame($first->id, $a->subscription_id);
        $this->assertSame($second->id, $b->subscription_id);
        $this->assertSame('Ожидает списания', $b->fresh()->chargeStatus()['label']);

        $this->travelTo(now()->addDay()->setTime(0, 5));
        $this->artisan('studio:charge-pending-bookings')->assertSuccessful();

        $this->assertNotNull($a->fresh()->subscription_usage_id);
        $this->assertNull($b->fresh()->subscription_usage_id);

        $this->travelTo(now()->addDays(2));
        $this->artisan('studio:charge-pending-bookings')->assertSuccessful();

        $this->assertNotNull($b->fresh()->subscription_usage_id);
        $this->assertSame(1, $first->fresh()->sessions_used);
        $this->assertSame(1, $second->fresh()->sessions_used);
    }

    public function test_cannot_put_second_early_booking_on_promised_single_subscription(): void
    {
        $user = $this->client();
        $sub = $this->single($user, now()->addDay());

        $this->service->bookForAdmin($user, $this->classAt(now()->addDay()->setTime(13, 0)), $sub);

        $this->expectException(InvalidArgumentException::class);

        $this->service->bookForAdmin($user, $this->classAt(now()->addDays(2)->setTime(13, 0)), $sub);
    }

    public function test_no_subscription_offered_when_its_only_session_is_promised(): void
    {
        $user = $this->client();
        $this->single($user, now()->addDay());

        $this->service->bookForAdmin($user, $this->classAt(now()->addDay()->setTime(13, 0)));

        $this->expectException(InvalidArgumentException::class);

        $this->service->bookForAdmin($user, $this->classAt(now()->addDays(2)->setTime(13, 0)));
    }

    public function test_stuck_booking_moves_to_next_subscription_when_charged(): void
    {
        $user = $this->client();
        $exhausted = $this->single($user, now()->subDays(5));
        $exhausted->update(['sessions_used' => 1]);
        $next = $this->single($user, now()->subDays(2));

        $booking = Booking::create([
            'user_id' => $user->id,
            'class_session_id' => $this->classAt(now()->subDay()->setTime(16, 45))->id,
            'subscription_id' => $exhausted->id,
            'status' => BookingStatus::Confirmed,
            'attendance_status' => AttendanceStatus::Attended,
        ]);

        $this->assertTrue($this->service->chargePendingBooking($booking));

        $booking->refresh();
        $this->assertSame($next->id, $booking->subscription_id);
        $this->assertNotNull($booking->subscription_usage_id);
        $this->assertSame(1, $next->fresh()->sessions_used);
        $this->assertSame(1, $exhausted->fresh()->sessions_used);
    }

    public function test_stuck_booking_without_replacement_stays_and_does_not_fail(): void
    {
        $user = $this->client();
        $exhausted = $this->single($user, now()->subDays(5));
        $exhausted->update(['sessions_used' => 1]);

        $booking = Booking::create([
            'user_id' => $user->id,
            'class_session_id' => $this->classAt(now()->subDay()->setTime(16, 45))->id,
            'subscription_id' => $exhausted->id,
            'status' => BookingStatus::Confirmed,
            'attendance_status' => AttendanceStatus::Attended,
        ]);

        $this->artisan('studio:charge-pending-bookings')->assertSuccessful();

        $this->assertNull($booking->fresh()->subscription_usage_id);
        $this->assertSame($exhausted->id, $booking->fresh()->subscription_id);
    }

    public function test_marking_attended_after_no_show_charges_again(): void
    {
        $user = $this->client();
        $sub = $this->single($user, now()->subDay());
        $booking = $this->service->bookForAdmin($user, $this->classAt(now()->addHours(2)));

        $this->service->markNoShow($booking);
        $this->assertSame(0, $sub->fresh()->sessions_used);

        $this->service->markAttended($booking);

        $this->assertNotNull($booking->fresh()->subscription_usage_id);
        $this->assertSame(1, $sub->fresh()->sessions_used);
    }

    public function test_nightly_charge_skips_no_show(): void
    {
        $user = $this->client();
        $sub = $this->single($user, now()->subDay());
        $booking = $this->service->bookForAdmin($user, $this->classAt(now()->addHours(2)));

        $this->service->markNoShow($booking);
        $this->artisan('studio:charge-pending-bookings')->assertSuccessful();

        $this->assertNull($booking->fresh()->subscription_usage_id);
        $this->assertSame(0, $sub->fresh()->sessions_used);
    }
}
