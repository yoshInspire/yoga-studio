<?php

namespace Tests\Feature;

use App\Enums\ClassSessionStatus;
use App\Enums\SubscriptionType;
use App\Enums\UserRole;
use App\Mail\StudioNotificationMail;
use App\Models\ClassSession;
use App\Models\ClientNotification;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AdminActivityNotifier;
use App\Services\BookingService;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Уведомления сотрудникам (ADMIN_PLAN_2.md, фаза L).
 *
 * Две вещи, которых раньше не было. Администратор узнавал о событиях только
 * почтой — а его рабочее место переехало в телефон. У тренера лента в
 * приложении была, но приходить в неё было нечему: `notifyUser()` звали
 * только для клиентов.
 *
 * Каналы у ролей разные, и это решение студии: администратору по-прежнему
 * уходит письмо, тренеру — только лента и пуш. Письмо «клиент записался»
 * на каждую запись превратило бы почту тренера в свалку.
 */
class StaffNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-06-10 12:00:00', config('app.timezone')));
    }

    private function trainer(): User
    {
        return User::factory()->create(['role' => UserRole::Trainer]);
    }

    private function client(): User
    {
        return User::factory()->create(['role' => UserRole::Client]);
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

    private function classOf(?User $trainer): ClassSession
    {
        return ClassSession::create([
            'trainer_id' => $trainer?->id,
            'topic' => 'Хатха-йога',
            'starts_at' => now()->addDays(2)->setTime(10, 0),
            'type' => SubscriptionType::Group,
            'capacity' => 6,
            'status' => ClassSessionStatus::Scheduled,
        ]);
    }

    public function test_admin_gets_the_notification_in_the_app_feed_as_well_as_by_mail(): void
    {
        Mail::fake();

        $admin = User::factory()->create(['role' => UserRole::Admin]);

        app(NotificationService::class)->notifyAdmin('Новый клиент', ['Иванова Анна'], type: 'studio');

        $this->assertSame(1, ClientNotification::query()->where('user_id', $admin->id)->count());
        Mail::assertSent(StudioNotificationMail::class);
    }

    /** Администраторов может быть несколько — лента у каждого своя. */
    public function test_every_admin_gets_their_own_feed_entry(): void
    {
        Mail::fake();

        User::factory()->count(2)->create(['role' => UserRole::Admin]);

        app(NotificationService::class)->notifyAdmin('Оплата', ['3 500 ₽']);

        $this->assertSame(2, ClientNotification::query()->count());
    }

    /** Клиент про служебные уведомления администратора знать не должен. */
    public function test_clients_do_not_get_admin_notifications(): void
    {
        Mail::fake();

        User::factory()->create(['role' => UserRole::Admin]);
        $client = $this->client();

        app(NotificationService::class)->notifyAdmin('Новый клиент', ['Иванова Анна']);

        $this->assertSame(0, ClientNotification::query()->where('user_id', $client->id)->count());
    }

    public function test_trainer_learns_that_the_class_was_cancelled(): void
    {
        Mail::fake();

        $trainer = $this->trainer();
        $session = $this->classOf($trainer);

        app(BookingService::class)->cancelClass($session, 'Тренер заболел');

        $note = ClientNotification::query()->where('user_id', $trainer->id)->first();

        $this->assertNotNull($note);
        $this->assertSame('Занятие отменено', $note->title);
        $this->assertStringContainsString('Тренер заболел', $note->body);
        $this->assertSame($session->id, $note->payload['session_id']);
    }

    /** Тренеру писем и Telegram не шлём — только лента и пуш. */
    public function test_trainer_is_not_emailed(): void
    {
        Mail::fake();

        $trainer = $this->trainer();
        $session = $this->classOf($trainer);

        app(BookingService::class)->cancelClass($session, 'Тренер заболел');

        Mail::assertNotSent(StudioNotificationMail::class, function (StudioNotificationMail $mail) use ($trainer) {
            return $mail->hasTo($trainer->email);
        });
    }

    public function test_class_without_a_trainer_does_not_break_cancellation(): void
    {
        Mail::fake();

        $session = $this->classOf(null);

        app(BookingService::class)->cancelClass($session, 'Ремонт в зале');

        $this->assertSame(ClassSessionStatus::Cancelled, $session->refresh()->status);
        $this->assertSame(0, ClientNotification::query()->count());
    }

    public function test_trainer_sees_a_new_booking_on_their_class(): void
    {
        Mail::fake();

        $trainer = $this->trainer();
        $session = $this->classOf($trainer);
        $client = $this->client();
        $this->subscription($client);

        $booking = app(BookingService::class)->bookForAdmin($client, $session);
        app(AdminActivityNotifier::class)->clientBooked($client, $booking);

        $note = ClientNotification::query()->where('user_id', $trainer->id)->first();

        $this->assertNotNull($note);
        $this->assertSame('Новая запись на занятие', $note->title);
        $this->assertStringContainsString($client->fullName(), $note->body);
    }

    public function test_trainer_sees_that_a_client_dropped_out(): void
    {
        Mail::fake();

        $trainer = $this->trainer();
        $session = $this->classOf($trainer);
        $client = $this->client();
        $this->subscription($client);

        $booking = app(BookingService::class)->bookForAdmin($client, $session);
        app(AdminActivityNotifier::class)->clientCancelledBooking($client, $booking);

        $this->assertSame(
            1,
            ClientNotification::query()
                ->where('user_id', $trainer->id)
                ->where('title', 'Клиент снялся с занятия')
                ->count(),
        );
    }
}
