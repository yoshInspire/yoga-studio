<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\ClassSessionStatus;
use App\Enums\SubscriptionType;
use App\Enums\UserRole;
use App\Mail\StudioNotificationMail;
use App\Models\ClassSession;
use App\Models\Subscription;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class StudioNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Бронирование в подготовке шлёт клиенту памятку «К вашему визиту».
        // Здесь проверяется автоотмена занятий, поэтому памятку выключаем —
        // иначе она попадает в подсчёт отправленных писем.
        config(['studio.mailings.welcome_visit.enabled' => false]);
    }

    private function client(string $phone = '+79990000001', ?string $email = 'client@example.com'): User
    {
        return User::create([
            'first_name' => 'Иван',
            'last_name' => 'Петров',
            'phone' => $phone,
            'email' => $email,
            'role' => UserRole::Client,
            'password' => 'secret123',
        ]);
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

    public function test_underfilled_group_class_is_auto_cancelled_with_notifications(): void
    {
        Mail::fake();

        config([
            'studio.auto_cancel.min_group_size' => 2,
            'studio.auto_cancel.morning_hours' => 10,
            'studio.auto_cancel.day_hours' => 10,
        ]);

        $user = $this->client();
        $sub = $this->subscription($user);

        $session = ClassSession::create([
            'topic' => 'Хатха-йога',
            'starts_at' => now()->addHours(2),
            'type' => SubscriptionType::Group,
            'capacity' => 6,
            'status' => ClassSessionStatus::Scheduled,
        ]);

        app(BookingService::class)->book($user, $session);
        $this->assertSame(1, $sub->fresh()->sessions_used);

        $this->artisan('studio:cancel-underfilled')->assertExitCode(0);

        $this->assertSame(ClassSessionStatus::Cancelled, $session->fresh()->status);
        $this->assertSame(0, $sub->fresh()->sessions_used);
        $this->assertSame(1, $session->bookings()->where('status', BookingStatus::ClassCancelled)->count());

        // Письмо клиенту + письмо администратору.
        Mail::assertSent(StudioNotificationMail::class, 2);
        Mail::assertSent(StudioNotificationMail::class, fn (StudioNotificationMail $mail) => $mail->hasTo('client@example.com'));
    }

    public function test_empty_group_class_is_auto_cancelled_with_admin_notification(): void
    {
        Mail::fake();

        config([
            'studio.auto_cancel.min_group_size' => 2,
            'studio.auto_cancel.morning_hours' => 10,
            'studio.auto_cancel.day_hours' => 10,
        ]);

        $session = ClassSession::create([
            'topic' => 'Гамак',
            'starts_at' => now()->addHours(2),
            'type' => SubscriptionType::Group,
            'capacity' => 5,
            'status' => ClassSessionStatus::Scheduled,
        ]);

        $this->artisan('studio:cancel-underfilled')->assertExitCode(0);

        $this->assertSame(ClassSessionStatus::Cancelled, $session->fresh()->status);
        $this->assertSame(
            'Занятие отменено автоматически: группа не набралась (меньше 2 человек).',
            $session->fresh()->cancellation_reason,
        );
        Mail::assertSent(StudioNotificationMail::class, 1);
    }

    public function test_full_enough_group_is_not_auto_cancelled(): void
    {
        Mail::fake();

        config([
            'studio.auto_cancel.min_group_size' => 2,
            'studio.auto_cancel.morning_hours' => 10,
            'studio.auto_cancel.day_hours' => 10,
        ]);

        $session = ClassSession::create([
            'topic' => 'Хатха-йога',
            'starts_at' => now()->addHours(2),
            'type' => SubscriptionType::Group,
            'capacity' => 6,
            'status' => ClassSessionStatus::Scheduled,
        ]);

        $a = $this->client('+79990000001', 'a@example.com');
        $this->subscription($a);
        app(BookingService::class)->book($a, $session);

        $b = $this->client('+79990000002', 'b@example.com');
        $this->subscription($b);
        app(BookingService::class)->book($b, $session);

        $this->artisan('studio:cancel-underfilled')->assertExitCode(0);

        $this->assertSame(ClassSessionStatus::Scheduled, $session->fresh()->status);
        Mail::assertNothingSent();
    }

    public function test_class_before_checkpoint_is_not_cancelled_yet(): void
    {
        Mail::fake();

        config([
            'studio.auto_cancel.min_group_size' => 2,
            'studio.auto_cancel.morning_hours' => 5,
            'studio.auto_cancel.day_hours' => 5,
        ]);

        $user = $this->client();
        $this->subscription($user);

        // Старт через 20 часов — контрольная точка (старт-5ч) ещё не наступила.
        $session = ClassSession::create([
            'topic' => 'Хатха-йога',
            'starts_at' => now()->addHours(20),
            'type' => SubscriptionType::Group,
            'capacity' => 6,
            'status' => ClassSessionStatus::Scheduled,
        ]);

        app(BookingService::class)->book($user, $session);

        $this->artisan('studio:cancel-underfilled')->assertExitCode(0);

        $this->assertSame(ClassSessionStatus::Scheduled, $session->fresh()->status);
        Mail::assertNothingSent();
    }

    public function test_morning_auto_cancel_checkpoint_is_fifteen_hours_before_start(): void
    {
        $session = ClassSession::create([
            'topic' => 'Хатха-йога',
            'starts_at' => Carbon::parse('2026-06-11 10:00:00', config('app.timezone')),
            'type' => SubscriptionType::Group,
            'capacity' => 6,
            'status' => ClassSessionStatus::Scheduled,
        ]);

        $checkpoint = $session->autoCancelCheckpoint();

        $this->assertSame('2026-06-10 19:00:00', $checkpoint->format('Y-m-d H:i:s'));
        $this->assertSame(15, $session->autoCancelDeadlineHours());
    }

    public function test_afternoon_auto_cancel_checkpoint_is_five_hours_before_start(): void
    {
        $session = ClassSession::create([
            'topic' => 'Хатха-йога',
            'starts_at' => Carbon::parse('2026-06-11 18:00:00', config('app.timezone')),
            'type' => SubscriptionType::Group,
            'capacity' => 6,
            'status' => ClassSessionStatus::Scheduled,
        ]);

        $checkpoint = $session->autoCancelCheckpoint();

        $this->assertSame('2026-06-11 13:00:00', $checkpoint->format('Y-m-d H:i:s'));
        $this->assertSame(5, $session->autoCancelDeadlineHours());
    }

    public function test_low_sessions_reminder_is_sent_once(): void
    {
        Mail::fake();

        $user = $this->client();
        $sub = $this->subscription($user, ['sessions_total' => 4, 'sessions_used' => 3]);

        $this->artisan('studio:subscription-reminders')->assertExitCode(0);

        $this->assertNotNull($sub->fresh()->low_sessions_notified_at);
        Mail::assertSent(StudioNotificationMail::class, 1);

        // Повторный запуск не дублирует уведомление.
        $this->artisan('studio:subscription-reminders')->assertExitCode(0);
        Mail::assertSent(StudioNotificationMail::class, 1);
    }

    public function test_expiring_reminder_is_sent_for_soon_to_end_subscription(): void
    {
        Mail::fake();

        $user = $this->client();
        $sub = $this->subscription($user, [
            'sessions_total' => 4,
            'sessions_used' => 1,
            'starts_at' => now()->subDays(25),
            'ends_at' => now()->addDays(3),
        ]);

        $this->artisan('studio:subscription-reminders')->assertExitCode(0);

        $this->assertNotNull($sub->fresh()->expiring_notified_at);
        Mail::assertSent(StudioNotificationMail::class, fn (StudioNotificationMail $mail) => str_contains($mail->heading, 'Срок абонемента'));
    }
}
