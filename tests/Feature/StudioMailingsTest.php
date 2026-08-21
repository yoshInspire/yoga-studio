<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\ClassSessionStatus;
use App\Enums\SubscriptionType;
use App\Enums\UserRole;
use App\Mail\StudioNotificationMail;
use App\Models\ClassSession;
use App\Models\ClientMailingLog;
use App\Models\Subscription;
use App\Models\User;
use App\Services\BookingService;
use App\Services\MailingSubscriptionService;
use App\Services\StudioMailingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class StudioMailingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-06-10 20:00:00', config('app.timezone')));
    }

    private function eligibleClient(string $phone = '+79990000001', ?string $email = 'client@example.com'): User
    {
        return User::create([
            'first_name' => 'Иван',
            'last_name' => 'Петров',
            'phone' => $phone,
            'email' => $email,
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

    public function test_daily_reminder_with_bookings_lists_tomorrow_classes(): void
    {
        Mail::fake();

        $user = $this->eligibleClient();
        $this->subscription($user);

        $session = ClassSession::create([
            'topic' => 'Хатха-йога',
            'starts_at' => now()->addDay()->setTime(10, 0),
            'type' => SubscriptionType::Group,
            'capacity' => 6,
            'status' => ClassSessionStatus::Scheduled,
        ]);

        app(BookingService::class)->book($user, $session);

        $this->artisan('studio:daily-booking-reminders')->assertExitCode(0);

        Mail::assertSent(StudioNotificationMail::class, function (StudioNotificationMail $mail) use ($user) {
            return $mail->hasTo($user->email)
                && str_contains($mail->heading, 'Напоминание')
                && collect($mail->lines)->contains(fn (string $line) => str_contains($line, '10:00'));
        });

        $this->assertDatabaseHas('client_mailing_logs', [
            'user_id' => $user->id,
            'type' => ClientMailingLog::TYPE_DAILY_EVENING,
            'mailing_key' => now()->addDay()->toDateString(),
        ]);
    }

    public function test_daily_reminder_without_bookings_sends_good_night_message(): void
    {
        Mail::fake();

        $user = $this->eligibleClient();
        $this->subscription($user);

        $this->artisan('studio:daily-booking-reminders')->assertExitCode(0);

        Mail::assertSent(StudioNotificationMail::class, fn (StudioNotificationMail $mail) => $mail->hasTo($user->email)
            && str_contains($mail->heading, 'Спокойной ночи'));
    }

    public function test_daily_reminder_is_not_sent_twice_for_same_day(): void
    {
        Mail::fake();

        $user = $this->eligibleClient();
        $this->subscription($user);

        $this->artisan('studio:daily-booking-reminders')->assertExitCode(0);
        $this->artisan('studio:daily-booking-reminders')->assertExitCode(0);

        Mail::assertSent(StudioNotificationMail::class, 1);
    }

    /**
     * Главное изменение: «завтра занятий нет» — это приглашение записаться, и
     * человеку без действующего абонемента оно ни о чём. Раньше такое письмо
     * уходило каждый вечер всей базе, включая давно ушедших клиентов, и было
     * основным источником отчётов о недоставке и жалоб на спам.
     */
    public function test_daily_reminder_without_bookings_skips_clients_without_active_subscription(): void
    {
        Mail::fake();

        $this->eligibleClient();

        $this->artisan('studio:daily-booking-reminders')->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_daily_reminder_without_bookings_skips_expired_subscription(): void
    {
        Mail::fake();

        $user = $this->eligibleClient();
        $subscription = $this->subscription($user);
        $subscription->forceFill([
            'starts_at' => now()->subMonths(3),
            'ends_at' => now()->subMonths(2),
        ])->save();

        $this->artisan('studio:daily-booking-reminders')->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_daily_reminder_without_bookings_skips_unsubscribed_client(): void
    {
        Mail::fake();

        $user = $this->eligibleClient();
        $this->subscription($user);
        app(MailingSubscriptionService::class)->unsubscribe($user);

        $this->artisan('studio:daily-booking-reminders')->assertExitCode(0);

        Mail::assertNothingSent();
    }

    /**
     * Напоминание о собственной записи — не рассылка: место занято, занятие
     * с абонемента списано. Отписка его не отменяет, и ссылки на отписку в
     * нём нет — предлагать отписаться от того, что всё равно придёт, нечестно.
     */
    public function test_daily_reminder_with_bookings_reaches_unsubscribed_client(): void
    {
        Mail::fake();

        $user = $this->eligibleClient();
        $this->subscription($user);
        app(MailingSubscriptionService::class)->unsubscribe($user);

        $session = ClassSession::create([
            'topic' => 'Хатха-йога',
            'starts_at' => now()->addDay()->setTime(10, 0),
            'type' => SubscriptionType::Group,
            'capacity' => 6,
            'status' => ClassSessionStatus::Scheduled,
        ]);

        app(BookingService::class)->book($user, $session);

        $this->artisan('studio:daily-booking-reminders')->assertExitCode(0);

        Mail::assertSent(StudioNotificationMail::class, fn (StudioNotificationMail $mail) => $mail->hasTo($user->email)
            && str_contains($mail->heading, 'Напоминание')
            && $mail->unsubscribeUrl === null);
    }

    public function test_evening_nudge_carries_unsubscribe_link(): void
    {
        Mail::fake();

        $user = $this->eligibleClient();
        $this->subscription($user);

        $this->artisan('studio:daily-booking-reminders')->assertExitCode(0);

        Mail::assertSent(StudioNotificationMail::class, fn (StudioNotificationMail $mail) => $mail->unsubscribeUrl !== null
            && str_contains($mail->unsubscribeUrl, '/mailings/unsubscribe/'.$user->id));
    }

    public function test_weekly_announcement_skips_unsubscribed_client(): void
    {
        Mail::fake();

        Carbon::setTestNow(Carbon::parse('2026-06-14 14:00:00', config('app.timezone')));

        $user = $this->eligibleClient();
        app(MailingSubscriptionService::class)->unsubscribe($user);

        $this->artisan('studio:weekly-schedule-announcement')->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_daily_reminder_skips_clients_without_offer_acceptance(): void
    {
        Mail::fake();

        User::create([
            'first_name' => 'Без',
            'last_name' => 'Оферты',
            'phone' => '+79990000099',
            'email' => 'no-offer@example.com',
            'role' => UserRole::Client,
            'password' => 'secret123',
        ]);

        $this->artisan('studio:daily-booking-reminders')->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_weekly_schedule_announcement_is_sent_once_per_week(): void
    {
        Mail::fake();

        Carbon::setTestNow(Carbon::parse('2026-06-14 14:00:00', config('app.timezone')));

        $user = $this->eligibleClient();

        $this->artisan('studio:weekly-schedule-announcement')->assertExitCode(0);
        $this->artisan('studio:weekly-schedule-announcement')->assertExitCode(0);

        Mail::assertSent(StudioNotificationMail::class, 1);
        Mail::assertSent(StudioNotificationMail::class, fn (StudioNotificationMail $mail) => $mail->hasTo($user->email)
            && str_contains($mail->heading, 'Расписание'));

        $this->assertDatabaseHas('client_mailing_logs', [
            'user_id' => $user->id,
            'type' => ClientMailingLog::TYPE_WEEKLY_SCHEDULE,
            'mailing_key' => '2026-06-15',
        ]);
    }

    public function test_weekly_schedule_force_resends(): void
    {
        Mail::fake();

        Carbon::setTestNow(Carbon::parse('2026-06-14 14:00:00', config('app.timezone')));

        $this->eligibleClient();

        $this->artisan('studio:weekly-schedule-announcement')->assertExitCode(0);
        $this->artisan('studio:weekly-schedule-announcement', ['--force' => true])->assertExitCode(0);

        Mail::assertSent(StudioNotificationMail::class, 2);
    }

    public function test_announcement_week_range_on_sunday_starts_next_monday(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-14 12:00:00', config('app.timezone')));

        [$start, $end] = app(StudioMailingService::class)->announcementWeekRange();

        $this->assertSame('2026-06-15', $start->toDateString());
        $this->assertSame('2026-06-21', $end->toDateString());
    }

    public function test_daily_reminder_ignores_cancelled_bookings(): void
    {
        Mail::fake();

        $user = $this->eligibleClient();
        $this->subscription($user);

        $session = ClassSession::create([
            'topic' => 'Хатха-йога',
            'starts_at' => now()->addDay()->setTime(10, 0),
            'type' => SubscriptionType::Group,
            'capacity' => 6,
            'status' => ClassSessionStatus::Scheduled,
        ]);

        $booking = app(BookingService::class)->book($user, $session);
        $booking->update(['status' => BookingStatus::CancelledByClient]);

        $this->artisan('studio:daily-booking-reminders')->assertExitCode(0);

        Mail::assertSent(StudioNotificationMail::class, fn (StudioNotificationMail $mail) => str_contains($mail->heading, 'Спокойной ночи'));
    }

    public function test_custom_announcement_sends_to_all_eligible_clients(): void
    {
        Mail::fake();

        $first = $this->eligibleClient('+79990000001', 'first@example.com');
        $second = $this->eligibleClient('+79990000002', 'second@example.com');

        $result = app(StudioMailingService::class)->sendCustomAnnouncement(
            heading: 'Важное объявление',
            body: "Здравствуйте!\n\nЗавтра студия работает по особому расписанию.",
        );

        $this->assertSame(2, $result['sent']);

        Mail::assertSent(StudioNotificationMail::class, 2);
        Mail::assertSent(StudioNotificationMail::class, fn (StudioNotificationMail $mail) => $mail->hasTo($first->email)
            && $mail->heading === 'Важное объявление'
            && collect($mail->lines)->contains(fn (string $line) => str_contains($line, 'особому расписанию')));

        $this->assertDatabaseHas('client_mailing_logs', [
            'user_id' => $second->id,
            'type' => ClientMailingLog::TYPE_CUSTOM,
        ]);
    }

    public function test_custom_announcement_skips_clients_without_offer_acceptance(): void
    {
        Mail::fake();

        $this->eligibleClient();

        User::create([
            'first_name' => 'Без',
            'last_name' => 'Оферты',
            'phone' => '+79990000099',
            'email' => 'no-offer@example.com',
            'role' => UserRole::Client,
            'password' => 'secret123',
        ]);

        $result = app(StudioMailingService::class)->sendCustomAnnouncement(
            heading: 'Новость студии',
            body: 'Текст для клиентов.',
        );

        $this->assertSame(1, $result['sent']);
        Mail::assertSent(StudioNotificationMail::class, 1);
    }
}
