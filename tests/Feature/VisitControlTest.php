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
use App\Services\VisitControlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VisitControlTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'first_name' => 'Админ',
            'last_name' => 'Студии',
            'phone' => '+79990000099',
            'email' => 'admin@example.com',
            'role' => UserRole::Admin,
            'password' => 'secret123',
        ]);
    }

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

    private function subscription(User $user): Subscription
    {
        return Subscription::create([
            'user_id' => $user->id,
            'type' => SubscriptionType::Group,
            'sessions_total' => 4,
            'sessions_used' => 0,
            'purchased_at' => now(),
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
        ]);
    }

    private function classSession(): ClassSession
    {
        return ClassSession::create([
            'topic' => 'Хатха-йога',
            'starts_at' => now()->startOfDay()->addHours(14),
            'type' => SubscriptionType::Group,
            'capacity' => 6,
            'status' => ClassSessionStatus::Scheduled,
        ]);
    }

    public function test_visit_control_page_is_available_for_admin(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get('/admin/visit-control')
            ->assertOk()
            ->assertSee('Контроль посещений')
            ->assertSee('Сегодня');
    }

    public function test_build_day_lists_confirmed_bookings_with_subscription_info(): void
    {
        $user = $this->client();
        $sub = $this->subscription($user);
        $session = $this->classSession();

        $booking = app(BookingService::class)->bookForAdmin($user, $session);

        $day = app(VisitControlService::class)->buildDay(now()->startOfDay());

        $this->assertSame(1, $day['stats']['sessions']);
        $this->assertSame(1, $day['stats']['bookings']);
        $this->assertSame(1, $day['stats']['pending']);

        $attendee = $day['sessions'][0]['attendees'][0];
        $this->assertSame($booking->id, $attendee['booking_id']);
        $this->assertSame('Петров Иван', $attendee['name']);
        $this->assertSame('Списано', $attendee['charge_label']);
        $this->assertSame(3, $attendee['sessions_remaining']);
        $this->assertTrue($attendee['attendance_pending']);
    }

    public function test_mark_attended_updates_booking_status(): void
    {
        $user = $this->client();
        $this->subscription($user);
        $session = $this->classSession();
        $booking = app(BookingService::class)->bookForAdmin($user, $session);

        app(BookingService::class)->markAttended($booking);

        $booking->refresh();
        $this->assertSame(AttendanceStatus::Attended, $booking->attendance_status);
        $this->assertNotNull($booking->attended_at);
        $this->assertSame(1, $booking->subscription->fresh()->sessions_used);
    }

    public function test_mark_no_show_refunds_subscription_session(): void
    {
        $user = $this->client();
        $sub = $this->subscription($user);
        $session = $this->classSession();
        $booking = app(BookingService::class)->bookForAdmin($user, $session);

        $this->assertSame(1, $sub->fresh()->sessions_used);

        app(BookingService::class)->markNoShow($booking);

        $booking->refresh();
        $this->assertSame(AttendanceStatus::NoShow, $booking->attendance_status);
        $this->assertNull($booking->subscription_usage_id);
        $this->assertSame(0, $sub->fresh()->sessions_used);
        $this->assertSame(BookingStatus::Confirmed, $booking->status);
    }
}
