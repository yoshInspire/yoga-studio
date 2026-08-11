<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\SendClientMailing;
use App\Mail\StudioNotificationMail;
use App\Models\BirthdayGreeting;
use App\Models\ClientMailingLog;
use App\Models\StudioText;
use App\Models\User;
use App\Services\WelcomeMessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Рассылки клиентам из приложения (ADMIN_PLAN_2.md, фаза I).
 *
 * Проверяем ровно то, что отличает эти маршруты от остальной админки:
 * отправка идёт живым людям и в журнал, повтор без `force` молчит, а экран
 * должен заранее знать, сколько человек получит письмо.
 */
class AdminMailingApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Четверг: анонс недели считается на ближайший понедельник.
        Carbon::setTestNow(Carbon::parse('2026-06-11 12:00:00', config('app.timezone')));
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    private function eligibleClient(string $phone, string $email): User
    {
        return User::create([
            'first_name' => 'Иван',
            'last_name' => 'Петров',
            'patronymic' => null,
            'phone' => $phone,
            'email' => $email,
            'role' => UserRole::Client,
            'password' => 'secret123',
            'offer_accepted_at' => now(),
        ]);
    }

    public function test_client_cannot_reach_mailings(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Client]), 'sanctum')
            ->getJson('/api/v1/admin/mailings')
            ->assertForbidden();
    }

    public function test_trainer_cannot_send_custom_announcement(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Trainer]), 'sanctum')
            ->postJson('/api/v1/admin/mailings/custom', ['heading' => 'Тема', 'body' => 'Текст'])
            ->assertForbidden();
    }

    public function test_index_returns_texts_recipients_and_schedule(): void
    {
        $this->eligibleClient('+79990000001', 'one@example.com');
        $this->eligibleClient('+79990000002', 'two@example.com');
        // Оферту не принял — в получатели не попадает.
        User::factory()->create(['role' => UserRole::Client, 'offer_accepted_at' => null]);

        $payload = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/mailings')
            ->assertOk()
            ->json();

        $this->assertSame(2, $payload['recipients']);
        $this->assertSame(app(WelcomeMessageService::class)->defaultBody(), $payload['welcome']['body']);
        $this->assertSame($payload['welcome']['body'], $payload['welcome']['default_body']);
        $this->assertSame('20:00', $payload['daily']['time']);
        $this->assertSame('14:00', $payload['weekly']['time']);
        $this->assertSame(0, $payload['weekly']['sent']);
        $this->assertSame(2, $payload['weekly']['pending']);
        $this->assertStringContainsString('июня', $payload['weekly']['from']);
    }

    public function test_welcome_text_is_saved_and_returned(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/v1/admin/mailings/welcome', ['body' => "Первая строка\n\nВторая строка"])
            ->assertOk()
            ->assertJsonPath('body', "Первая строка\n\nВторая строка");

        $this->assertSame(
            "Первая строка\n\nВторая строка",
            StudioText::body(StudioText::WELCOME_VISIT, 'нет'),
        );
    }

    public function test_welcome_text_cannot_be_emptied(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/v1/admin/mailings/welcome', ['body' => ''])
            ->assertStatus(422);
    }

    public function test_birthday_greetings_are_replaced_as_a_whole(): void
    {
        // Варианты приезжают миграцией — заводить свой первый нельзя
        // (`position` уникален), поэтому метим существующий.
        BirthdayGreeting::query()->orderBy('position')->first()
            ?->update(['body' => 'Старое поздравление']);

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/v1/admin/mailings/birthday', [
                'greetings' => ['Первый вариант', 'Второй вариант'],
            ])
            ->assertOk()
            ->assertJsonPath('greetings.0', 'Первый вариант')
            ->assertJsonPath('greetings.1', 'Второй вариант');

        $this->assertSame(2, BirthdayGreeting::query()->count());
        $this->assertSame(0, BirthdayGreeting::query()->where('body', 'Старое поздравление')->count());
    }

    public function test_birthday_greetings_require_at_least_one(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/v1/admin/mailings/birthday', ['greetings' => []])
            ->assertStatus(422);
    }

    public function test_weekly_announcement_reaches_eligible_clients(): void
    {
        Mail::fake();

        $one = $this->eligibleClient('+79990000001', 'one@example.com');
        $this->eligibleClient('+79990000002', 'two@example.com');

        $payload = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/mailings/weekly')
            ->assertOk()
            ->json();

        $this->assertSame(2, $payload['sent']);
        $this->assertSame(0, $payload['skipped']);
        // Ответ несёт свежее состояние экрана — второй запрос не нужен.
        $this->assertSame(2, $payload['state']['weekly']['sent']);
        $this->assertSame(0, $payload['state']['weekly']['pending']);

        Mail::assertSent(StudioNotificationMail::class, 2);
        $this->assertSame(
            1,
            ClientMailingLog::query()
                ->where('user_id', $one->id)
                ->where('type', ClientMailingLog::TYPE_WEEKLY_SCHEDULE)
                ->count(),
        );
    }

    public function test_weekly_announcement_skips_those_who_already_got_it(): void
    {
        Mail::fake();

        $this->eligibleClient('+79990000001', 'one@example.com');

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/mailings/weekly')
            ->assertOk();

        $payload = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/mailings/weekly')
            ->assertOk()
            ->json();

        $this->assertSame(0, $payload['sent']);
        $this->assertSame(1, $payload['skipped']);
        Mail::assertSent(StudioNotificationMail::class, 1);
    }

    public function test_weekly_announcement_with_force_sends_again(): void
    {
        Mail::fake();

        $this->eligibleClient('+79990000001', 'one@example.com');

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/mailings/weekly')
            ->assertOk();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/mailings/weekly', ['force' => true])
            ->assertOk()
            ->assertJsonPath('sent', 1);

        Mail::assertSent(StudioNotificationMail::class, 2);
        // Журнал не раздувается: прошлая отметка на эту же неделю заменяется.
        $this->assertSame(
            1,
            ClientMailingLog::query()->where('type', ClientMailingLog::TYPE_WEEKLY_SCHEDULE)->count(),
        );
    }

    public function test_custom_announcement_uses_heading_as_subject(): void
    {
        Mail::fake();

        $this->eligibleClient('+79990000001', 'one@example.com');

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/mailings/custom', [
                'heading' => 'Расписание на праздники',
                'body' => "Здравствуйте!\n\n10 июня студия не работает.",
            ])
            ->assertOk()
            ->assertJsonPath('sent', 1);

        Mail::assertSent(StudioNotificationMail::class, function (StudioNotificationMail $mail) {
            return $mail->heading === 'Расписание на праздники'
                // Пустые строки не уходят абзацами — их отбрасывает сервис.
                && $mail->lines === ['Здравствуйте!', '10 июня студия не работает.'];
        });
    }

    public function test_custom_announcement_requires_heading_and_body(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/mailings/custom', ['heading' => '', 'body' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['heading', 'body']);
    }

    /**
     * Разбор инцидента 06.08.2026: сообщение ушло клиентам четыре раза.
     *
     * Отправка не укладывалась в таймаут, страница показывала ошибку, и
     * администратор жал «Отправить» снова. Теперь повтор того же текста
     * второй копии не шлёт.
     */
    public function test_repeated_custom_announcement_does_not_send_second_copy(): void
    {
        Mail::fake();

        $this->eligibleClient('+79990000001', 'one@example.com');

        $payload = ['heading' => 'Тема', 'body' => 'Текст сообщения'];

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/mailings/custom', $payload)
            ->assertOk()
            ->assertJsonPath('queued', 1);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/mailings/custom', $payload)
            ->assertOk()
            ->assertJsonPath('queued', 0)
            ->assertJsonPath('already_sent', true);

        Mail::assertSent(StudioNotificationMail::class, 1);
        $this->assertSame(
            1,
            ClientMailingLog::query()->where('type', ClientMailingLog::TYPE_CUSTOM)->count(),
        );
    }

    /**
     * Второе оповещение за день с другим текстом обязано уходить.
     *
     * До 11.08.2026 оно падало с ошибкой уникальности журнала — уже после
     * того, как сообщение ушло первому клиенту в списке.
     */
    public function test_second_custom_announcement_with_other_text_goes_out_same_day(): void
    {
        Mail::fake();

        $this->eligibleClient('+79990000001', 'one@example.com');
        $this->eligibleClient('+79990000002', 'two@example.com');

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/mailings/custom', ['heading' => 'Утро', 'body' => 'Занятие перенесено'])
            ->assertOk()
            ->assertJsonPath('queued', 2);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/mailings/custom', ['heading' => 'Вечер', 'body' => 'Занятие отменено'])
            ->assertOk()
            ->assertJsonPath('queued', 2);

        Mail::assertSent(StudioNotificationMail::class, 4);
    }

    /**
     * Запрос только ставит задания: ждать в нём семь десятков SMTP-сессий
     * нельзя — именно это и обрывалось по таймауту.
     */
    public function test_custom_announcement_is_queued_and_does_not_send_in_request(): void
    {
        Mail::fake();
        Queue::fake();

        $this->eligibleClient('+79990000001', 'one@example.com');
        $this->eligibleClient('+79990000002', 'two@example.com');

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/mailings/custom', ['heading' => 'Тема', 'body' => 'Текст'])
            ->assertOk()
            ->assertJsonPath('queued', 2);

        Queue::assertPushed(SendClientMailing::class, 2);
        Mail::assertNothingSent();
    }

    public function test_dry_run_is_not_exposed(): void
    {
        Mail::fake();

        $this->eligibleClient('+79990000001', 'one@example.com');

        // Лишний параметр не должен превращать отправку в проверку: в
        // приложении режима «просто посмотреть» нет.
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/mailings/custom', [
                'heading' => 'Тема',
                'body' => 'Текст',
                'dry_run' => true,
            ])
            ->assertOk()
            ->assertJsonPath('sent', 1);

        Mail::assertSent(StudioNotificationMail::class, 1);
    }
}
