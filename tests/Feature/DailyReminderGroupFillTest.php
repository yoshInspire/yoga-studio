<?php

namespace Tests\Feature;

use App\Enums\ClassSessionStatus;
use App\Enums\SubscriptionType;
use App\Enums\UserRole;
use App\Models\ClassSession;
use App\Models\Subscription;
use App\Models\User;
use App\Services\BookingService;
use App\Services\StudioMailingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Напоминание накануне не должно обещать занятие как состоявшееся, если группа
 * ещё не набралась: иначе клиент получает «занятие будет», а следом «отменено».
 */
class DailyReminderGroupFillTest extends TestCase
{
    use RefreshDatabase;

    private int $phoneSeq = 0;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function clientWithSubscription(): User
    {
        $this->phoneSeq++;

        $user = User::create([
            'first_name' => 'Клиент'.$this->phoneSeq,
            'last_name' => 'Тестовый',
            'phone' => '+7999000'.str_pad((string) $this->phoneSeq, 4, '0', STR_PAD_LEFT),
            'email' => 'client'.$this->phoneSeq.'@example.test',
            'role' => UserRole::Client,
            'password' => 'secret123',
        ]);

        Subscription::create([
            'user_id' => $user->id,
            'type' => SubscriptionType::Group,
            'sessions_total' => 8,
            'sessions_used' => 0,
            'purchased_at' => now(),
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
        ]);

        return $user;
    }

    private function tomorrowSession(): ClassSession
    {
        return ClassSession::create([
            'topic' => 'Хатха-йога',
            'starts_at' => now()->addDay()->setTime(19, 0),
            'type' => SubscriptionType::Group,
            'capacity' => 6,
            'status' => ClassSessionStatus::Scheduled,
        ]);
    }

    private function reminderLinesFor(User $user): string
    {
        $mailings = app(StudioMailingService::class);
        $bookings = \App\Models\Booking::query()
            ->where('user_id', $user->id)
            ->where('status', \App\Enums\BookingStatus::Confirmed)
            ->with(['classSession' => fn ($q) => $q->withCount([
                'bookings as confirmed_count' => fn ($b) => $b->where('status', \App\Enums\BookingStatus::Confirmed),
            ])])
            ->get();

        $message = $mailings->buildDailyWithBookingsMessage(
            $user,
            now()->addDay()->startOfDay(),
            $bookings,
        );

        return implode("\n", $message['lines']);
    }

    public function test_reminder_warns_when_group_is_not_filled_yet(): void
    {
        Carbon::setTestNow('2026-07-14 20:00:00');

        $user = $this->clientWithSubscription();
        $session = $this->tomorrowSession();
        app(BookingService::class)->book($user, $session);

        $text = $this->reminderLinesFor($user);

        $this->assertStringContainsString('Группа пока набирается', $text);
        $this->assertStringContainsString('вернётся на ваш абонемент', $text);
    }

    public function test_reminder_has_no_warning_when_group_is_filled(): void
    {
        Carbon::setTestNow('2026-07-14 20:00:00');

        $session = $this->tomorrowSession();
        $bookings = app(BookingService::class);

        $first = $this->clientWithSubscription();
        $second = $this->clientWithSubscription();
        $bookings->book($first, $session);
        $bookings->book($second, $session);

        $text = $this->reminderLinesFor($first);

        $this->assertStringNotContainsString('Группа пока набирается', $text);
        $this->assertStringContainsString('Хатха-йога', $text);
    }

    public function test_individual_session_never_warns_about_group(): void
    {
        Carbon::setTestNow('2026-07-14 20:00:00');

        $user = $this->clientWithSubscription();
        Subscription::create([
            'user_id' => $user->id,
            'type' => SubscriptionType::Individual,
            'sessions_total' => 4,
            'sessions_used' => 0,
            'purchased_at' => now(),
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
        ]);

        $session = ClassSession::create([
            'topic' => 'Индивидуальное',
            'starts_at' => now()->addDay()->setTime(19, 0),
            'type' => SubscriptionType::Individual,
            'capacity' => 1,
            'status' => ClassSessionStatus::Scheduled,
        ]);

        app(BookingService::class)->book($user, $session);

        $this->assertStringNotContainsString('Группа пока набирается', $this->reminderLinesFor($user));
    }
}
