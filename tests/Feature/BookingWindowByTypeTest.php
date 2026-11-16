<?php

namespace Tests\Feature;

use App\Enums\ClassSessionStatus;
use App\Enums\SubscriptionType;
use App\Enums\UserRole;
use App\Models\ClassSession;
use App\Models\Subscription;
use App\Models\User;
use App\Services\BookingService;
use App\Support\StudioRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Срок записи по типу занятия (Ирина, 16.11.2026): групповые — 7 дней,
 * индивидуальные и мероприятия — 14. Пока запись не открылась, вместо
 * «Отменено» видно, когда она откроется.
 */
class BookingWindowByTypeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-16 11:00:00'));
        config([
            'studio.booking_days_ahead' => 7,
            'studio.booking_days_ahead_by_type' => ['individual' => 14, 'special_event' => 14],
            'studio.mailings.welcome_visit.enabled' => false,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function client(SubscriptionType $type): User
    {
        $user = User::factory()->create(['role' => UserRole::Client, 'offer_accepted_at' => now()]);

        Subscription::create([
            'user_id' => $user->id,
            'type' => $type,
            'sessions_total' => 4,
            'sessions_used' => 0,
            'purchased_at' => now(),
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonths(2),
        ]);

        return $user;
    }

    private function classSession(SubscriptionType $type, Carbon $startsAt): ClassSession
    {
        return ClassSession::create([
            'topic' => 'Хатха-йога',
            'starts_at' => $startsAt,
            'type' => $type,
            'capacity' => $type === SubscriptionType::Group ? 6 : 1,
            'status' => ClassSessionStatus::Scheduled,
        ]);
    }

    private function slotFor(ClassSession $session, ?User $viewer, int $weekOffset): array
    {
        $service = app(BookingService::class);

        foreach ($service->buildRollingSchedule($service->scheduleStart($weekOffset), $viewer) as $day) {
            foreach ($day['slots'] as $slot) {
                if ($slot['id'] === $session->id) {
                    return $slot;
                }
            }
        }

        $this->fail('Занятия нет в расписании.');
    }

    public function test_individual_session_ten_days_ahead_is_bookable(): void
    {
        $session = $this->classSession(SubscriptionType::Individual, Carbon::parse('2026-11-26 18:00'));
        $client = $this->client(SubscriptionType::Individual);

        $slot = $this->slotFor($session, $client, 1);
        $this->assertTrue($slot['bookable']);
        $this->assertNull($slot['closed_label']);

        app(BookingService::class)->book($client, $session);
        $this->assertSame(1, $session->bookings()->count());
    }

    public function test_special_event_uses_the_long_window_too(): void
    {
        $session = $this->classSession(SubscriptionType::SpecialEvent, Carbon::parse('2026-11-29 19:00'));

        $this->assertTrue($session->isWithinBookingWindow());
    }

    public function test_group_session_stays_on_seven_days_and_says_when_booking_opens(): void
    {
        $session = $this->classSession(SubscriptionType::Group, Carbon::parse('2026-11-24 18:00'));
        $client = $this->client(SubscriptionType::Group);

        $slot = $this->slotFor($session, $client, 1);
        $this->assertSame('open', $slot['status']);
        $this->assertFalse($slot['bookable']);
        $this->assertSame('Запись откроется 17.11 в 18:00', $slot['closed_label']);

        $this->expectException(InvalidArgumentException::class);
        app(BookingService::class)->book($client, $session);
    }

    public function test_individual_beyond_fourteen_days_says_when_booking_opens(): void
    {
        $session = $this->classSession(SubscriptionType::Individual, Carbon::parse('2026-12-03 10:00'));

        $this->assertSame('Запись откроется 19.11 в 10:00', $this->slotFor($session, null, 2)['closed_label']);
    }

    public function test_started_session_says_booking_closed(): void
    {
        $session = $this->classSession(SubscriptionType::Group, Carbon::parse('2026-11-16 10:00'));

        $this->assertSame('Запись закрыта', $this->slotFor($session, null, 0)['closed_label']);
    }

    public function test_rules_lead_names_both_windows(): void
    {
        $lead = StudioRules::lead();

        $this->assertStringContainsString('7 дней', $lead);
        $this->assertStringContainsString('14 дней', $lead);
    }
}
