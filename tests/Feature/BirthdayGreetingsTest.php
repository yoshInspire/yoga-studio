<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Mail\StudioNotificationMail;
use App\Models\BirthdayGreeting;
use App\Models\ClientMailingLog;
use App\Models\User;
use App\Services\BirthdayGreetingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class BirthdayGreetingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-10 09:00:00', config('app.timezone')));
    }

    private function eligibleClient(array $overrides = []): User
    {
        return User::create(array_merge([
            'first_name' => 'Иван',
            'last_name' => 'Петров',
            'phone' => '+7999'.random_int(1000000, 9999999),
            'email' => 'client'.random_int(1, 99999).'@example.com',
            'role' => UserRole::Client,
            'password' => 'secret123',
            'offer_accepted_at' => now(),
            'birth_day' => 10,
            'birth_month' => 7,
        ], $overrides));
    }

    public function test_sends_birthday_greeting_on_matching_day(): void
    {
        Mail::fake();

        $user = $this->eligibleClient();
        $expectedBody = app(BirthdayGreetingService::class)->orderedBodies()[0];

        $this->artisan('studio:birthday-greetings')->assertExitCode(0);

        Mail::assertSent(StudioNotificationMail::class, function (StudioNotificationMail $mail) use ($user, $expectedBody) {
            return $mail->hasTo($user->email)
                && str_contains($mail->heading, 'днём рождения')
                && ($mail->lines[0] ?? '') === $expectedBody;
        });

        $this->assertDatabaseHas('client_mailing_logs', [
            'user_id' => $user->id,
            'type' => ClientMailingLog::TYPE_BIRTHDAY,
            'mailing_key' => '2026-07-10 00:00:00',
        ]);
    }

    public function test_skips_if_already_sent_this_year(): void
    {
        Mail::fake();

        $user = $this->eligibleClient();

        ClientMailingLog::query()->create([
            'user_id' => $user->id,
            'type' => ClientMailingLog::TYPE_BIRTHDAY,
            'mailing_key' => '2026-07-10',
            'sent_at' => now()->subHours(2),
        ]);

        $this->artisan('studio:birthday-greetings')->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_rotates_greeting_variant_each_year(): void
    {
        Mail::fake();

        $user = $this->eligibleClient();
        $bodies = app(BirthdayGreetingService::class)->orderedBodies();

        ClientMailingLog::query()->create([
            'user_id' => $user->id,
            'type' => ClientMailingLog::TYPE_BIRTHDAY,
            'mailing_key' => '2025-07-10',
            'sent_at' => now()->subYear(),
        ]);

        $this->artisan('studio:birthday-greetings')->assertExitCode(0);

        Mail::assertSent(StudioNotificationMail::class, fn (StudioNotificationMail $mail) => $mail->hasTo($user->email)
            && ($mail->lines[0] ?? '') === $bodies[1]);
    }

    public function test_skips_clients_without_birth_date(): void
    {
        Mail::fake();

        $this->eligibleClient([
            'birth_day' => null,
            'birth_month' => null,
        ]);

        $this->artisan('studio:birthday-greetings')->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_sends_feb_29_birthday_on_feb_28_in_non_leap_year(): void
    {
        Mail::fake();

        Carbon::setTestNow(Carbon::parse('2027-02-28 09:00:00', config('app.timezone')));

        $user = $this->eligibleClient([
            'birth_day' => 29,
            'birth_month' => 2,
            'phone' => '+79991112233',
            'email' => 'feb29@example.com',
        ]);

        $this->artisan('studio:birthday-greetings')->assertExitCode(0);

        Mail::assertSent(StudioNotificationMail::class, fn (StudioNotificationMail $mail) => $mail->hasTo($user->email));
    }

    public function test_admin_can_add_and_remove_greeting_variants(): void
    {
        $bodies = app(BirthdayGreetingService::class)->orderedBodies();

        app(BirthdayGreetingService::class)->syncBodies([
            $bodies[0],
            'Новый дополнительный вариант поздравления.',
        ]);

        $updated = app(BirthdayGreetingService::class)->orderedBodies();

        $this->assertCount(2, $updated);
        $this->assertSame('Новый дополнительный вариант поздравления.', $updated[1]);

        app(BirthdayGreetingService::class)->syncBodies([$updated[0]]);

        $this->assertCount(1, app(BirthdayGreetingService::class)->orderedBodies());
    }

    public function test_admin_can_update_greeting_texts(): void
    {
        $custom = 'С днём рождения! Тестовый текст студии.';

        app(BirthdayGreetingService::class)->syncBodies([
            $custom,
            BirthdayGreeting::query()->where('position', 2)->value('body'),
            BirthdayGreeting::query()->where('position', 3)->value('body'),
            BirthdayGreeting::query()->where('position', 4)->value('body'),
            BirthdayGreeting::query()->where('position', 5)->value('body'),
        ]);

        $this->assertSame($custom, app(BirthdayGreetingService::class)->orderedBodies()[0]);
    }
}
