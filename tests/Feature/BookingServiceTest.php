<?php

namespace Tests\Feature;

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
use InvalidArgumentException;
use Tests\TestCase;

class BookingServiceTest extends TestCase
{
    use RefreshDatabase;

    private BookingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(BookingService::class);
    }

    private function client(string $phone = '+79990000001'): User
    {
        return User::create([
            'first_name' => 'Иван',
            'last_name' => 'Петров',
            'phone' => $phone,
            'role' => UserRole::Client,
            'password' => 'secret123',
        ]);
    }

    private function makeSession(array $overrides = []): ClassSession
    {
        return ClassSession::create(array_merge([
            'topic' => 'Хатха-йога',
            'starts_at' => now()->addDay()->setTime(10, 0),
            'type' => SubscriptionType::Group,
            'capacity' => 6,
            'status' => ClassSessionStatus::Scheduled,
        ], $overrides));
    }

    private function subscription(User $user, array $overrides = []): Subscription
    {
        return Subscription::create(array_merge([
            'user_id' => $user->id,
            'type' => SubscriptionType::Group,
            'sessions_total' => 4,
            'sessions_used' => 0,
            'purchased_at' => now(),
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
        ], $overrides));
    }

    public function test_booking_deducts_from_matching_subscription(): void
    {
        $user = $this->client();
        $sub = $this->subscription($user);
        $session = $this->makeSession();

        $booking = $this->service->book($user, $session);

        $this->assertSame(BookingStatus::Confirmed, $booking->status);
        $this->assertSame($sub->id, $booking->subscription_id);
        $this->assertSame(1, $sub->fresh()->sessions_used);
    }

    public function test_cannot_book_without_matching_subscription(): void
    {
        $user = $this->client();
        $this->subscription($user, ['type' => SubscriptionType::Individual]);
        $session = $this->makeSession(['type' => SubscriptionType::Group]);

        try {
            $this->service->book($user, $session);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('нет абонемента для этого типа', $e->getMessage());
        }
    }

    public function test_cannot_book_when_subscription_starts_later_than_class(): void
    {
        $user = $this->client();
        $this->subscription($user, [
            'sessions_total' => 1,
            'starts_at' => now()->addDays(2),
            'ends_at' => now()->addDays(32),
        ]);
        $session = $this->makeSession(['starts_at' => now()->addDay()->setTime(10, 0)]);

        try {
            $this->service->book($user, $session);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('начнёт действовать', $e->getMessage());
        }
    }

    public function test_booking_defers_charge_when_subscription_starts_on_class_day(): void
    {
        $user = $this->client();
        $classDay = now()->addDay()->startOfDay();
        $sub = $this->subscription($user, [
            'type' => SubscriptionType::Individual,
            'sessions_total' => 1,
            'starts_at' => $classDay,
            'ends_at' => $classDay->copy()->addMonth(),
        ]);
        $session = $this->makeSession([
            'type' => SubscriptionType::Individual,
            'starts_at' => $classDay->copy()->setTime(18, 0),
            'capacity' => 1,
        ]);

        $booking = $this->service->book($user, $session, $sub);

        $this->assertSame(BookingStatus::Confirmed, $booking->status);
        $this->assertSame($sub->id, $booking->subscription_id);
        $this->assertNull($booking->subscription_usage_id);
        $this->assertSame(0, $sub->fresh()->sessions_used);

        $this->travelTo($classDay->copy()->setTime(8, 0));

        $this->assertTrue($this->service->chargePendingBooking($booking->fresh()));
        $this->assertSame(1, $sub->fresh()->sessions_used);
        $this->assertNotNull($booking->fresh()->subscription_usage_id);
    }

    public function test_admin_can_book_outside_public_booking_window(): void
    {
        $user = $this->client();
        $this->subscription($user);
        $session = $this->makeSession(['starts_at' => now()->addDays(10)->setTime(10, 0)]);

        try {
            $this->service->book($user, $session);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('недоступна', $e->getMessage());
        }

        $booking = $this->service->bookForAdmin($user, $session);

        $this->assertSame(BookingStatus::Confirmed, $booking->status);
    }

    public function test_cannot_book_when_all_sessions_used(): void
    {
        $user = $this->client();
        $this->subscription($user, ['sessions_total' => 1, 'sessions_used' => 1]);
        $session = $this->makeSession();

        try {
            $this->service->book($user, $session);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('не осталось занятий', $e->getMessage());
        }
    }

    public function test_cannot_use_group_subscription_for_individual_class(): void
    {
        $user = $this->client();
        $group = $this->subscription($user, ['type' => SubscriptionType::Group]);
        $session = $this->makeSession(['type' => SubscriptionType::Individual, 'capacity' => 1]);

        $this->expectException(InvalidArgumentException::class);
        $this->service->book($user, $session, $group);
    }

    public function test_group_booking_uses_group_subscription_when_event_was_purchased_first(): void
    {
        $user = $this->client();

        $event = $this->subscription($user, [
            'type' => SubscriptionType::SpecialEvent,
            'sessions_total' => 1,
            'purchased_at' => now()->subHours(2),
            'starts_at' => now()->addDays(2),
            'ends_at' => now()->addDays(32),
        ]);

        $group = $this->subscription($user, [
            'type' => SubscriptionType::Group,
            'sessions_total' => 4,
            'purchased_at' => now()->subHour(),
            'starts_at' => now(),
            'ends_at' => now()->addDays(30),
        ]);

        $saturdayGroup = $this->makeSession([
            'topic' => 'Хатха-йога',
            'type' => SubscriptionType::Group,
            'starts_at' => now()->addDays(1)->setTime(10, 0),
        ]);

        $booking = $this->service->book($user, $saturdayGroup);

        $this->assertSame($group->id, $booking->subscription_id);
        $this->assertSame(1, $group->fresh()->sessions_used);
        $this->assertSame(0, $event->fresh()->sessions_used);
    }

    public function test_event_booking_uses_event_subscription_when_group_was_purchased_later(): void
    {
        $user = $this->client();

        $event = $this->subscription($user, [
            'type' => SubscriptionType::SpecialEvent,
            'sessions_total' => 1,
            'purchased_at' => now()->subHours(2),
            'starts_at' => now(),
            'ends_at' => now()->addDays(30),
        ]);

        $group = $this->subscription($user, [
            'type' => SubscriptionType::Group,
            'sessions_total' => 4,
            'purchased_at' => now()->subHour(),
            'starts_at' => now(),
            'ends_at' => now()->addDays(30),
        ]);

        $sundayEvent = $this->makeSession([
            'topic' => 'Йога-нидра',
            'type' => SubscriptionType::SpecialEvent,
            'starts_at' => now()->addDays(2)->setTime(18, 0),
        ]);

        $booking = $this->service->book($user, $sundayEvent);

        $this->assertSame($event->id, $booking->subscription_id);
        $this->assertSame(1, $event->fresh()->sessions_used);
        $this->assertSame(0, $group->fresh()->sessions_used);
    }

    public function test_cannot_force_event_subscription_for_group_class(): void
    {
        $user = $this->client();

        $event = $this->subscription($user, [
            'type' => SubscriptionType::SpecialEvent,
            'sessions_total' => 1,
        ]);

        $session = $this->makeSession(['type' => SubscriptionType::Group]);

        $this->expectException(InvalidArgumentException::class);
        $this->service->book($user, $session, $event);
    }

    public function test_cannot_book_group_class_with_only_event_subscription(): void
    {
        $user = $this->client();

        $this->subscription($user, [
            'type' => SubscriptionType::SpecialEvent,
            'sessions_total' => 1,
        ]);

        $session = $this->makeSession(['type' => SubscriptionType::Group]);

        $this->expectException(InvalidArgumentException::class);
        $this->service->book($user, $session);
    }

    public function test_cannot_book_full_session(): void
    {
        $session = $this->makeSession(['capacity' => 1]);

        $first = $this->client('+79990000001');
        $this->subscription($first);
        $this->service->book($first, $session);

        $second = $this->client('+79990000002');
        $this->subscription($second);

        $this->expectException(InvalidArgumentException::class);
        $this->service->book($second, $session);
    }

    public function test_cannot_book_same_session_twice(): void
    {
        $user = $this->client();
        $this->subscription($user, ['sessions_total' => 10]);
        $session = $this->makeSession();

        $this->service->book($user, $session);

        $this->expectException(InvalidArgumentException::class);
        $this->service->book($user, $session);
    }

    public function test_enforces_max_bookings_per_day(): void
    {
        config(['studio.max_bookings_per_day' => 2]);

        $user = $this->client();
        $this->subscription($user, ['sessions_total' => 10]);

        $day = now()->addDay();
        $this->service->book($user, $this->makeSession(['starts_at' => $day->copy()->setTime(9, 0)]));
        $this->service->book($user, $this->makeSession(['starts_at' => $day->copy()->setTime(12, 0)]));

        $this->expectException(InvalidArgumentException::class);
        $this->service->book($user, $this->makeSession(['starts_at' => $day->copy()->setTime(18, 0)]));
    }

    public function test_admin_can_book_beyond_max_bookings_per_day(): void
    {
        config(['studio.max_bookings_per_day' => 2]);

        $user = $this->client();
        $this->subscription($user, ['sessions_total' => 10]);

        $day = now()->addDay();
        $this->service->book($user, $this->makeSession(['starts_at' => $day->copy()->setTime(9, 0)]));
        $this->service->book($user, $this->makeSession(['starts_at' => $day->copy()->setTime(12, 0)]));

        $booking = $this->service->bookForAdmin($user, $this->makeSession(['starts_at' => $day->copy()->setTime(18, 0)]));

        $this->assertSame(BookingStatus::Confirmed, $booking->status);
    }

    public function test_client_cancellation_refunds_subscription(): void
    {
        $user = $this->client();
        $sub = $this->subscription($user);
        $session = $this->makeSession(['starts_at' => now()->addDays(2)]);

        $booking = $this->service->book($user, $session);
        $this->assertSame(1, $sub->fresh()->sessions_used);

        $this->actingAs($user);
        $cancelled = $this->service->cancelByClient($booking);

        $this->assertSame(BookingStatus::CancelledByClient, $cancelled->status);
        $this->assertSame(0, $sub->fresh()->sessions_used);
    }

    public function test_client_cannot_cancel_within_deadline(): void
    {
        config(['studio.cancellation_deadline_hours' => 4]);

        $user = $this->client();
        $this->subscription($user);
        $session = $this->makeSession(['starts_at' => now()->addHours(2)]);

        $booking = $this->service->book($user, $session);

        $this->actingAs($user);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Вы не можете отменить запись в соответствии с правилами студии.');
        $this->service->cancelByClient($booking);
    }

    public function test_morning_class_uses_longer_cancellation_window(): void
    {
        config([
            'studio.cancellation.noon_hour' => 12,
            'studio.cancellation.morning_hours' => 14,
            'studio.cancellation.day_hours' => 4,
        ]);

        \Illuminate\Support\Carbon::setTestNow('2026-06-15 06:00:00');

        $user = $this->client();
        $this->subscription($user);

        // Утреннее занятие в этот же день в 09:00 — до начала 3 ч, окно утром 14 ч: нельзя.
        $morning = $this->makeSession(['starts_at' => '2026-06-15 09:00:00']);
        $booking = $this->service->book($user, $morning);

        $this->actingAs($user);

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->service->cancelByClient($booking);
        } finally {
            \Illuminate\Support\Carbon::setTestNow();
        }
    }

    public function test_afternoon_class_uses_shorter_cancellation_window(): void
    {
        config([
            'studio.cancellation.noon_hour' => 12,
            'studio.cancellation.morning_hours' => 14,
            'studio.cancellation.day_hours' => 4,
        ]);

        \Illuminate\Support\Carbon::setTestNow('2026-06-15 06:00:00');

        $user = $this->client();
        $sub = $this->subscription($user);

        // Дневное занятие в этот же день в 18:00 — до начала 12 ч, окно днём 4 ч: можно.
        $afternoon = $this->makeSession(['starts_at' => '2026-06-15 18:00:00']);
        $booking = $this->service->book($user, $afternoon);
        $this->assertSame(1, $sub->fresh()->sessions_used);

        $this->actingAs($user);
        $cancelled = $this->service->cancelByClient($booking);

        $this->assertSame(BookingStatus::CancelledByClient, $cancelled->status);
        $this->assertSame(0, $sub->fresh()->sessions_used);

        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_double_subscription_charges_two_sessions_for_single_class(): void
    {
        $user = $this->client();
        $sub = $this->subscription($user, ['sessions_total' => 2, 'sessions_per_day' => 2]);
        $session = $this->makeSession(['starts_at' => now()->addDays(2)->setTime(10, 0)]);

        $booking = $this->service->book($user, $session);

        $this->assertSame(2, $sub->fresh()->sessions_used);
        $this->assertSame(2, (int) $booking->subscriptionUsage->sessions_spent);
    }

    public function test_double_subscription_two_classes_same_day_charge_two_total(): void
    {
        config(['studio.max_bookings_per_day' => 2]);

        $user = $this->client();
        $sub = $this->subscription($user, ['sessions_total' => 2, 'sessions_per_day' => 2]);

        $day = now()->addDays(2);
        $first = $this->service->book($user, $this->makeSession(['starts_at' => $day->copy()->setTime(10, 0)]));
        $second = $this->service->book($user, $this->makeSession(['starts_at' => $day->copy()->setTime(18, 0)]));

        $this->assertSame(2, $sub->fresh()->sessions_used);
        $this->assertNotNull($first->fresh()->subscription_usage_id);
        $this->assertNull($second->fresh()->subscription_usage_id);
        $this->assertSame($sub->id, $second->subscription_id);
    }

    public function test_double_subscription_cancel_single_refunds_both(): void
    {
        $user = $this->client();
        $sub = $this->subscription($user, ['sessions_total' => 2, 'sessions_per_day' => 2]);
        $session = $this->makeSession(['starts_at' => now()->addDays(2)->setTime(10, 0)]);

        $booking = $this->service->book($user, $session);
        $this->assertSame(2, $sub->fresh()->sessions_used);

        $this->actingAs($user);
        $this->service->cancelByClient($booking);

        $this->assertSame(0, $sub->fresh()->sessions_used);
    }

    public function test_double_subscription_cancel_one_of_two_keeps_day_charged(): void
    {
        config(['studio.max_bookings_per_day' => 2]);

        $user = $this->client();
        $sub = $this->subscription($user, ['sessions_total' => 2, 'sessions_per_day' => 2]);

        $day = now()->addDays(2);
        $first = $this->service->book($user, $this->makeSession(['starts_at' => $day->copy()->setTime(10, 0)]));
        $second = $this->service->book($user, $this->makeSession(['starts_at' => $day->copy()->setTime(18, 0)]));

        $this->actingAs($user);

        // Отменяем «оплаченное» занятие — день всё ещё используется второй записью.
        $this->service->cancelByClient($first);
        $this->assertSame(2, $sub->fresh()->sessions_used);
        $this->assertNotNull($second->fresh()->subscription_usage_id);

        // Отмена второго (последнего) занятия дня возвращает оба занятия.
        $this->service->cancelByClient($second->fresh());
        $this->assertSame(0, $sub->fresh()->sessions_used);
    }

    public function test_client_can_reschedule_booking_without_extra_charge(): void
    {
        $user = $this->client();
        $sub = $this->subscription($user, ['sessions_total' => 4]);

        $day = now()->addDays(3);
        $original = $this->makeSession(['starts_at' => $day->copy()->setTime(10, 0)]);
        $target = $this->makeSession(['starts_at' => $day->copy()->setTime(18, 0)]);

        $booking = $this->service->book($user, $original);
        $this->assertSame(1, $sub->fresh()->sessions_used);

        $this->actingAs($user);
        $moved = $this->service->rescheduleByClient($booking, $target);

        $this->assertSame($target->id, $moved->class_session_id);
        $this->assertSame(BookingStatus::Confirmed, $moved->status);
        $this->assertSame(1, $sub->fresh()->sessions_used);
        $this->assertSame(1, Booking::query()->where('user_id', $user->id)->where('status', BookingStatus::Confirmed)->count());
    }

    public function test_reschedule_is_blocked_within_cancellation_deadline(): void
    {
        config([
            'studio.cancellation.noon_hour' => 12,
            'studio.cancellation.morning_hours' => 14,
            'studio.cancellation.day_hours' => 4,
        ]);

        \Illuminate\Support\Carbon::setTestNow('2026-06-15 06:00:00');

        $user = $this->client();
        $this->subscription($user);

        $original = $this->makeSession(['starts_at' => '2026-06-15 18:00:00']);
        $target = $this->makeSession(['starts_at' => '2026-06-16 10:00:00']);

        $booking = $this->service->book($user, $original);

        $this->actingAs($user);

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->service->rescheduleByClient($booking, $target);
        } finally {
            \Illuminate\Support\Carbon::setTestNow();
        }
    }

    public function test_cancel_class_refunds_all_clients_with_reason(): void
    {
        $session = $this->makeSession(['capacity' => 5]);

        $a = $this->client('+79990000001');
        $subA = $this->subscription($a);
        $this->service->book($a, $session);

        $b = $this->client('+79990000002');
        $subB = $this->subscription($b);
        $this->service->book($b, $session);

        $updated = $this->service->cancelClass($session, 'Недостаточно человек в группе');

        $this->assertSame(ClassSessionStatus::Cancelled, $updated->status);
        $this->assertSame('Недостаточно человек в группе', $updated->cancellation_reason);
        $this->assertSame(0, $subA->fresh()->sessions_used);
        $this->assertSame(0, $subB->fresh()->sessions_used);
        $this->assertSame(2, $session->bookings()->where('status', BookingStatus::ClassCancelled)->count());
    }
}
