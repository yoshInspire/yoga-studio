<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\ClassSessionStatus;
use App\Enums\SubscriptionType;
use App\Enums\UserRole;
use App\Mail\RegistrationVerificationMail;
use App\Mail\StudioNotificationMail;
use App\Models\ClassSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminActivityNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'studio.admin_email' => 'admin@example.com',
            'studio.admin_activity_notifications.enabled' => true,
            // Первое бронирование в подготовке шлёт клиенту памятку «К вашему
            // визиту». Здесь проверяются уведомления администратору, поэтому
            // лишнее письмо только мешает считать отправки.
            'studio.mailings.welcome_visit.enabled' => false,
        ]);
    }

    public function test_admin_is_notified_when_new_client_registers(): void
    {
        Mail::fake();

        $this->post(route('register'), [
            'first_name' => 'Мария',
            'last_name' => 'Иванова',
            'birth_day' => 5,
            'birth_month' => 6,
            'birth_year' => 1995,
            'phone' => '+79991234567',
            'email' => 'maria@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'offer_accepted' => '1',
        ]);

        $code = null;
        Mail::assertSent(RegistrationVerificationMail::class, function (RegistrationVerificationMail $mail) use (&$code) {
            $code = $mail->code;

            return true;
        });

        $this->post(route('register.verify'), ['code' => $code]);

        Mail::assertSent(
            StudioNotificationMail::class,
            fn (StudioNotificationMail $mail) => $mail->hasTo('admin@example.com')
                && str_contains($mail->heading, 'Новый клиент'),
        );
    }

    public function test_admin_is_notified_when_client_books_class(): void
    {
        Mail::fake();

        $user = User::create([
            'first_name' => 'Иван',
            'last_name' => 'Петров',
            'phone' => '79990000001',
            'email' => 'client@example.com',
            'role' => UserRole::Client,
            'password' => 'secret123',
            'offer_accepted_at' => now(),
        ]);

        \App\Models\Subscription::create([
            'user_id' => $user->id,
            'type' => SubscriptionType::Group,
            'sessions_total' => 4,
            'sessions_used' => 0,
            'purchased_at' => now(),
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
        ]);

        $session = ClassSession::create([
            'topic' => 'Хатха-йога',
            'starts_at' => now()->addDay()->setTime(10, 0),
            'type' => SubscriptionType::Group,
            'capacity' => 6,
            'status' => ClassSessionStatus::Scheduled,
        ]);

        $this->actingAs($user)->post(route('bookings.store'), [
            'class_session_id' => $session->id,
        ])->assertRedirect();

        Mail::assertSent(
            StudioNotificationMail::class,
            fn (StudioNotificationMail $mail) => $mail->hasTo('admin@example.com')
                && str_contains($mail->heading, 'Запись на занятие'),
        );
    }

    public function test_admin_activity_notifications_can_be_disabled(): void
    {
        Mail::fake();
        config(['studio.admin_activity_notifications.enabled' => false]);

        $user = User::create([
            'first_name' => 'Иван',
            'last_name' => 'Петров',
            'phone' => '79990000002',
            'email' => 'client2@example.com',
            'role' => UserRole::Client,
            'password' => 'secret123',
            'offer_accepted_at' => now(),
        ]);

        \App\Models\Subscription::create([
            'user_id' => $user->id,
            'type' => SubscriptionType::Group,
            'sessions_total' => 4,
            'sessions_used' => 0,
            'purchased_at' => now(),
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
        ]);

        $session = ClassSession::create([
            'topic' => 'Йога',
            'starts_at' => now()->addDay()->setTime(12, 0),
            'type' => SubscriptionType::Group,
            'capacity' => 6,
            'status' => ClassSessionStatus::Scheduled,
        ]);

        $this->actingAs($user)->post(route('bookings.store'), [
            'class_session_id' => $session->id,
        ])->assertRedirect();

        Mail::assertNothingSent();
    }
}
