<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Mail\StudioNotificationMail;
use App\Models\News;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class NewsNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private function eligibleClient(
        string $phone = '+79990000001',
        ?string $email = 'client@example.com',
        ?int $telegramId = null,
    ): User {
        return User::create([
            'first_name' => 'Иван',
            'last_name' => 'Петров',
            'phone' => $phone,
            'email' => $email,
            'telegram_id' => $telegramId,
            'role' => UserRole::Client,
            'password' => 'secret123',
            'offer_accepted_at' => now(),
        ]);
    }

    private function publishedNews(array $overrides = []): News
    {
        return News::query()->create(array_merge([
            'title' => 'Йога на крыше',
            'body' => 'Приглашаем на открытое занятие.',
            'excerpt' => 'Открытое занятие в субботу.',
            'is_published' => true,
            'published_at' => now(),
        ], $overrides));
    }

    public function test_publishing_news_notifies_eligible_client_by_email(): void
    {
        Mail::fake();

        $this->eligibleClient();

        $news = $this->publishedNews();

        Mail::assertSent(StudioNotificationMail::class, function (StudioNotificationMail $mail) use ($news) {
            return $mail->hasTo('client@example.com')
                && str_contains($mail->heading, 'Новость')
                && collect($mail->lines)->contains(fn (string $line) => str_contains($line, $news->title));
        });

        $this->assertNotNull($news->fresh()->notifications_sent_at);
    }

    public function test_publishing_news_notifies_client_with_telegram(): void
    {
        Mail::fake();
        config(['services.telegram.bot_token' => '123456:TESTTOKEN']);

        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $this->eligibleClient(email: null, telegramId: 1160272287);

        $this->publishedNews();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMessage')
            && $request['chat_id'] === 1160272287);

        Mail::assertNothingSent();
    }

    public function test_unpublished_news_does_not_notify(): void
    {
        Mail::fake();

        $this->eligibleClient();

        News::query()->create([
            'title' => 'Черновик',
            'body' => 'Текст.',
            'is_published' => false,
            'published_at' => now(),
        ]);

        Mail::assertNothingSent();
    }

    public function test_future_publication_date_does_not_notify_until_scheduled(): void
    {
        Mail::fake();

        $this->eligibleClient();

        $news = News::query()->create([
            'title' => 'Анонс',
            'body' => 'Текст анонса.',
            'is_published' => true,
            'published_at' => now()->addDay(),
        ]);

        Mail::assertNothingSent();
        $this->assertNull($news->fresh()->notifications_sent_at);

        Carbon::setTestNow(now()->addDay()->addMinute());

        try {
            $this->artisan('studio:publish-scheduled-news')->assertExitCode(0);

            Mail::assertSent(StudioNotificationMail::class, 1);
            $this->assertNotNull($news->fresh()->notifications_sent_at);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_already_notified_news_is_not_sent_again_on_edit(): void
    {
        Mail::fake();

        $this->eligibleClient();

        $news = $this->publishedNews();

        Mail::assertSent(StudioNotificationMail::class, 1);

        $news->update(['title' => 'Обновлённый заголовок']);

        Mail::assertSent(StudioNotificationMail::class, 1);
    }

    public function test_clients_without_offer_acceptance_are_skipped(): void
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

        $this->publishedNews();

        Mail::assertNothingSent();
    }
}
