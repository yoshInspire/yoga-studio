<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Mail\StudioNotificationMail;
use App\Models\News;
use App\Models\User;
use App\Services\MailingSubscriptionService;
use App\Services\NewsNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Отписка от рассылок: ссылка из письма, кнопка почтового клиента и кабинет.
 */
class MailingUnsubscribeTest extends TestCase
{
    use RefreshDatabase;

    private function client(): User
    {
        return User::create([
            'first_name' => 'Иван',
            'last_name' => 'Петров',
            'phone' => '+79990000001',
            'email' => 'client@example.com',
            'role' => UserRole::Client,
            'password' => 'secret123',
            'offer_accepted_at' => now(),
        ]);
    }

    public function test_signed_link_shows_confirmation_page_without_unsubscribing(): void
    {
        $user = $this->client();

        $this->get(app(MailingSubscriptionService::class)->unsubscribeUrl($user))
            ->assertOk()
            ->assertSee('Отписаться от рассылок?');

        // Роботы-предпросмотрщики ссылок в письмах ходят GET-запросами:
        // отписка на GET сработала бы без участия человека.
        $this->assertTrue($user->fresh()->isSubscribedToMailings());
    }

    public function test_post_unsubscribes(): void
    {
        $user = $this->client();

        $this->post(app(MailingSubscriptionService::class)->unsubscribeUrl($user))
            ->assertOk()
            ->assertSee('вы отписаны');

        $this->assertFalse($user->fresh()->isSubscribedToMailings());
    }

    /** Кнопка «Отписаться» самой почты (RFC 8058) ходит без токена формы. */
    public function test_one_click_unsubscribe_works_without_csrf_token(): void
    {
        $user = $this->client();

        $this->withMiddleware()
            ->post(
                app(MailingSubscriptionService::class)->unsubscribeUrl($user),
                ['List-Unsubscribe' => 'One-Click'],
            )
            ->assertOk();

        $this->assertFalse($user->fresh()->isSubscribedToMailings());
    }

    public function test_unsigned_link_is_rejected(): void
    {
        $user = $this->client();

        $this->get('/mailings/unsubscribe/'.$user->id)->assertForbidden();
        $this->post('/mailings/unsubscribe/'.$user->id)->assertForbidden();

        $this->assertTrue($user->fresh()->isSubscribedToMailings());
    }

    /** Чужую подпись на свой id не переклеить: подписывается весь адрес. */
    public function test_signature_of_another_user_does_not_work(): void
    {
        $victim = $this->client();
        $attacker = User::create([
            'first_name' => 'Пётр',
            'last_name' => 'Сидоров',
            'phone' => '+79990000002',
            'email' => 'attacker@example.com',
            'role' => UserRole::Client,
            'password' => 'secret123',
            'offer_accepted_at' => now(),
        ]);

        $signature = parse_url(
            app(MailingSubscriptionService::class)->unsubscribeUrl($attacker),
            PHP_URL_QUERY,
        );

        $this->post('/mailings/unsubscribe/'.$victim->id.'?'.$signature)->assertForbidden();

        $this->assertTrue($victim->fresh()->isSubscribedToMailings());
    }

    public function test_resubscribe_link_returns_subscription(): void
    {
        $user = $this->client();
        app(MailingSubscriptionService::class)->unsubscribe($user);

        $this->get(app(MailingSubscriptionService::class)->resubscribeUrl($user))
            ->assertOk()
            ->assertSee('Подписка возвращена');

        $this->assertTrue($user->fresh()->isSubscribedToMailings());
    }

    public function test_unsubscribed_client_gets_no_news_mailing(): void
    {
        Mail::fake();

        $user = $this->client();
        app(MailingSubscriptionService::class)->unsubscribe($user);

        $news = News::create([
            'title' => 'Новое направление',
            'slug' => 'novoe-napravlenie',
            'body' => 'Текст новости.',
            'is_published' => true,
            'published_at' => now(),
        ]);

        app(NewsNotificationService::class)->notifyClientsIfNeeded($news);

        Mail::assertNothingSent();
    }

    public function test_account_page_shows_subscription_state(): void
    {
        $user = $this->client();

        $this->actingAs($user)->get(route('account'))
            ->assertOk()
            ->assertSee('Рассылки студии')
            ->assertSee('Вы подписаны');

        app(MailingSubscriptionService::class)->unsubscribe($user);

        $this->actingAs($user->fresh())->get(route('account'))
            ->assertOk()
            ->assertSee('Вы отписаны');
    }

    public function test_client_can_unsubscribe_from_account_page(): void
    {
        $user = $this->client();

        $this->actingAs($user)
            ->put(route('account.mailings.update'), ['subscribed' => 0])
            ->assertRedirect(route('account'));

        $this->assertFalse($user->fresh()->isSubscribedToMailings());

        $this->actingAs($user)
            ->put(route('account.mailings.update'), ['subscribed' => 1])
            ->assertRedirect(route('account'));

        $this->assertTrue($user->fresh()->isSubscribedToMailings());
    }

    public function test_app_can_toggle_subscription(): void
    {
        $user = $this->client();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/account/mailings', ['subscribed' => false])
            ->assertOk()
            ->assertJsonPath('user.mailings_subscribed', false);

        $this->assertFalse($user->fresh()->isSubscribedToMailings());
    }

    public function test_mail_carries_one_click_unsubscribe_headers(): void
    {
        $user = $this->client();
        $url = app(MailingSubscriptionService::class)->unsubscribeUrl($user);

        $mail = new StudioNotificationMail('Заголовок', ['Строка'], 'Тема', null, $url);
        $headers = $mail->headers();

        $this->assertSame('<'.$url.'>', $headers->text['List-Unsubscribe']);
        $this->assertSame('List-Unsubscribe=One-Click', $headers->text['List-Unsubscribe-Post']);
    }

    /** Личное письмо отписки не предлагает: оно придёт в любом случае. */
    public function test_personal_mail_has_no_unsubscribe_headers(): void
    {
        $mail = new StudioNotificationMail('Заголовок', ['Строка'], 'Тема');

        $this->assertSame([], $mail->headers()->text);
    }

    public function test_unsubscribe_link_never_expires(): void
    {
        $user = $this->client();

        $url = app(MailingSubscriptionService::class)->unsubscribeUrl($user);

        // Письмо могут открыть спустя месяцы; протухшая ссылка на отписку —
        // это не отписка, а жалоба на спам.
        $this->travel(400)->days();

        $this->assertTrue(URL::hasValidSignature($this->createRequestFor($url)));
    }

    private function createRequestFor(string $url): Request
    {
        return Request::create($url);
    }
}
