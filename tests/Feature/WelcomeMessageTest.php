<?php

namespace Tests\Feature;

use App\Enums\ClassSessionStatus;
use App\Enums\SubscriptionType;
use App\Enums\UserRole;
use App\Mail\StudioNotificationMail;
use App\Models\ClassSession;
use App\Models\ClientMailingLog;
use App\Models\StudioText;
use App\Models\Subscription;
use App\Models\User;
use App\Services\BookingService;
use App\Services\WelcomeMessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Памятка «К вашему визиту» уходит один раз — при первом бронировании.
 */
class WelcomeMessageTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function client(?string $email = 'client@example.test'): User
    {
        $this->seq++;

        return User::create([
            'first_name' => 'Иван',
            'last_name' => 'Петров',
            'phone' => '+7999000'.str_pad((string) $this->seq, 4, '0', STR_PAD_LEFT),
            'email' => $email,
            'role' => UserRole::Client,
            'password' => 'secret123',
            'offer_accepted_at' => now(),
        ]);
    }

    private function subscriptionFor(User $user): Subscription
    {
        return Subscription::create([
            'user_id' => $user->id,
            'type' => SubscriptionType::Group,
            'sessions_total' => 8,
            'sessions_used' => 0,
            'purchased_at' => now(),
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
        ]);
    }

    private function classSession(int $hour = 10): ClassSession
    {
        return ClassSession::create([
            'topic' => 'Хатха-йога',
            'starts_at' => now()->addDay()->setTime($hour, 0),
            'type' => SubscriptionType::Group,
            'capacity' => 6,
            'status' => ClassSessionStatus::Scheduled,
        ]);
    }

    public function test_welcome_is_sent_on_the_first_booking(): void
    {
        Mail::fake();

        $user = $this->client();
        $this->subscriptionFor($user);

        app(BookingService::class)->book($user, $this->classSession());

        Mail::assertSent(StudioNotificationMail::class);

        $this->assertDatabaseHas('client_mailing_logs', [
            'user_id' => $user->id,
            'type' => ClientMailingLog::TYPE_WELCOME,
        ]);
    }

    public function test_welcome_is_not_sent_twice(): void
    {
        $user = $this->client();
        $this->subscriptionFor($user);
        $bookings = app(BookingService::class);

        $bookings->book($user, $this->classSession(10));

        Mail::fake();
        $bookings->book($user->fresh(), $this->classSession(12));

        Mail::assertNothingSent();

        $this->assertSame(1, ClientMailingLog::query()
            ->where('user_id', $user->id)
            ->where('type', ClientMailingLog::TYPE_WELCOME)
            ->count());
    }

    public function test_admin_booking_also_triggers_welcome(): void
    {
        Mail::fake();

        $user = $this->client();
        $this->subscriptionFor($user);

        app(BookingService::class)->bookForAdmin($user, $this->classSession());

        $this->assertTrue(app(WelcomeMessageService::class)->alreadySent($user));
    }

    public function test_admin_text_is_used_instead_of_default(): void
    {
        $service = app(WelcomeMessageService::class);
        $service->saveBody("Своя памятка\nВторая строка");

        $this->assertSame("Своя памятка\nВторая строка", $service->body());
        $this->assertSame(['Своя памятка', 'Вторая строка'], $service->lines());
    }

    public function test_default_text_returns_after_admin_text_is_removed(): void
    {
        $service = app(WelcomeMessageService::class);
        $service->saveBody('Временный текст');
        StudioText::query()->delete();

        $this->assertStringContainsString('ЭКО YOGA', $service->body());
    }

    public function test_default_text_uses_booking_wording(): void
    {
        $body = app(WelcomeMessageService::class)->defaultBody();

        $this->assertStringContainsString('забронировали место', $body);
        $this->assertStringNotContainsString('Вы записаны', $body);
    }

    public function test_blank_lines_are_dropped(): void
    {
        $service = app(WelcomeMessageService::class);
        $service->saveBody("Первая\n\n   \nВторая");

        $this->assertSame(['Первая', 'Вторая'], $service->lines());
    }

    public function test_client_without_contacts_is_skipped(): void
    {
        $user = $this->client(email: null);
        $this->subscriptionFor($user);

        app(BookingService::class)->book($user, $this->classSession());

        $this->assertFalse(app(WelcomeMessageService::class)->alreadySent($user));
    }

    public function test_disabled_in_config_means_nothing_is_sent(): void
    {
        config(['studio.mailings.welcome_visit.enabled' => false]);
        Mail::fake();

        $user = $this->client();
        $this->subscriptionFor($user);

        app(BookingService::class)->book($user, $this->classSession());

        Mail::assertNothingSent();
        $this->assertFalse(app(WelcomeMessageService::class)->alreadySent($user));
    }

    /**
     * Пустой текст сохранить через админку нельзя (поле обязательное), но если
     * он всё же окажется пустым — уходит текст по умолчанию. Промолчать здесь
     * хуже: клиент останется без памятки и не узнает, что взять с собой.
     */
    public function test_empty_text_falls_back_to_default(): void
    {
        $service = app(WelcomeMessageService::class);
        $service->saveBody('   ');

        $this->assertStringContainsString('ЭКО YOGA', $service->body());

        Mail::fake();

        $user = $this->client();
        $this->subscriptionFor($user);

        app(BookingService::class)->book($user, $this->classSession());

        Mail::assertSent(StudioNotificationMail::class);
    }
}
