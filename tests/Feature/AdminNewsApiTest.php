<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\News;
use App\Models\User;
use App\Support\ImageThumbnailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Новости из приложения (ADMIN_PLAN_2.md, фаза F).
 *
 * Отдельного внимания стоит рассылка: сохранение опубликованной новости уходит
 * письмами и в Telegram всем клиентам с принятой офертой. Приложение обязано
 * знать об этом заранее (`will_notify`, число получателей) и получать отчёт
 * после (`notified`) — иначе «Опубликовать» пальцем становится рулеткой.
 */
class AdminNewsApiTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    /** Клиент, который попадёт в рассылку: оферта принята и есть почта. */
    private function subscriber(): User
    {
        return User::factory()->create([
            'role' => UserRole::Client,
            'offer_accepted_at' => now()->subMonth(),
            'email' => 'client'.fake()->unique()->numberBetween(1, 99999).'@example.com',
        ]);
    }

    private function news(array $overrides = []): News
    {
        return News::create(array_merge([
            'title' => 'Открытие сезона',
            'body' => 'Текст новости.',
            'is_published' => false,
            'published_at' => null,
        ], $overrides));
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Новое направление',
            'excerpt' => 'Коротко о главном',
            'body' => 'Подробный текст новости.',
            'is_published' => false,
            'published_at' => null,
        ], $overrides);
    }

    public function test_client_cannot_reach_news_admin(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Client]), 'sanctum')
            ->getJson('/api/v1/admin/news')
            ->assertForbidden();
    }

    public function test_list_separates_drafts_scheduled_and_published(): void
    {
        $this->news(['title' => 'Черновик']);
        $this->news(['title' => 'Опубликованная', 'is_published' => true, 'published_at' => now()->subDay()]);
        $this->news(['title' => 'Запланированная', 'is_published' => true, 'published_at' => now()->addWeek()]);

        $all = $this->actingAs($this->admin(), 'sanctum')->getJson('/api/v1/admin/news')->assertOk()->json();
        $this->assertCount(3, $all['data']);

        $states = collect($all['data'])->pluck('state', 'title');
        $this->assertSame('draft', $states['Черновик']);
        $this->assertSame('published', $states['Опубликованная']);
        // Дата в будущем — клиент новость ещё не видит, значит для админки
        // это не «опубликовано», а «запланировано».
        $this->assertSame('scheduled', $states['Запланированная']);

        $published = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/news?status=published')->assertOk()->json();
        $this->assertSame(['Опубликованная'], collect($published['data'])->pluck('title')->all());

        $drafts = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/news?status=draft')->assertOk()->json();
        $this->assertEqualsCanonicalizing(
            ['Черновик', 'Запланированная'],
            collect($drafts['data'])->pluck('title')->all(),
        );
    }

    public function test_search_matches_title(): void
    {
        $this->news(['title' => 'Мастер-класс по йога-нидре']);
        $this->news(['title' => 'Расписание на праздники']);

        $found = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/news?q=нидре')->assertOk()->json();

        $this->assertSame(['Мастер-класс по йога-нидре'], collect($found['data'])->pluck('title')->all());
    }

    public function test_draft_is_created_without_sending_anything(): void
    {
        Notification::fake();
        $this->subscriber();

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/news', $this->payload())
            ->assertCreated();

        $response->assertJsonPath('notified', null);
        $response->assertJsonPath('data.state', 'draft');
        $response->assertJsonPath('data.will_notify', false);

        // Slug модель заводит сама из заголовка.
        $this->assertNotEmpty($response->json('data.slug'));
        $this->assertDatabaseHas('news', ['title' => 'Новое направление', 'notifications_sent_at' => null]);
    }

    public function test_publishing_reports_how_many_clients_were_notified(): void
    {
        Notification::fake();
        $this->subscriber();
        $this->subscriber();
        // Без принятой оферты — в рассылку не попадает.
        User::factory()->create(['role' => UserRole::Client, 'offer_accepted_at' => null]);

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/news', $this->payload(['is_published' => true]))
            ->assertCreated();

        $response->assertJsonPath('notified', 2);
        $this->assertStringContainsString('2', (string) $response->json('message'));
        $this->assertNotNull(News::first()->notifications_sent_at);
    }

    public function test_published_without_a_date_gets_one_so_clients_actually_see_it(): void
    {
        Notification::fake();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/news', $this->payload(['is_published' => true, 'published_at' => null]))
            ->assertCreated()
            ->assertJsonPath('data.state', 'published');

        $this->assertNotNull(News::first()->published_at);
    }

    public function test_second_save_does_not_notify_twice(): void
    {
        Notification::fake();
        $this->subscriber();

        $news = $this->news(['is_published' => true, 'published_at' => now()->subDay()]);
        $this->assertNotNull($news->refresh()->notifications_sent_at);

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/v1/admin/news/'.$news->id, $this->payload([
                'title' => 'Правленый заголовок',
                'is_published' => true,
                'published_at' => $news->published_at->toIso8601String(),
            ]))
            ->assertOk()
            ->assertJsonPath('notified', null)
            ->assertJsonPath('data.title', 'Правленый заголовок');
    }

    public function test_slug_survives_a_title_change(): void
    {
        Notification::fake();
        $news = $this->news(['title' => 'Первый заголовок']);
        $slug = $news->slug;

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/v1/admin/news/'.$news->id, $this->payload(['title' => 'Совсем другой заголовок']))
            ->assertOk()
            // Ссылка уже могла уйти клиентам в письме и пуше — менять её нельзя.
            ->assertJsonPath('data.slug', $slug);
    }

    public function test_validation_speaks_human(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/news', ['title' => '', 'body' => '', 'is_published' => false])
            ->assertStatus(422)
            ->assertJsonPath('errors.title.0', 'Напишите заголовок.')
            ->assertJsonPath('errors.body.0', 'Напишите текст новости.');
    }

    public function test_photo_is_stored_downscaled_with_a_thumbnail(): void
    {
        Notification::fake();
        Storage::fake('public');
        $news = $this->news();

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->post('/api/v1/admin/news/'.$news->id.'/image', [
                'photo' => UploadedFile::fake()->image('shot.jpg', 3000, 3000),
            ])
            ->assertOk();

        $path = $news->refresh()->image_path;
        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);

        // Форма кадра сохранена — бокс в приложении подстроится под неё.
        // Сравнение нестрогое: 1.0 уезжает в JSON как 1 и возвращается int.
        $this->assertEquals(1.0, $response->json('data.image_ratio'));

        // Уменьшенную копию делает NewsObserver — без неё лента дорожает.
        $this->assertNotNull(ImageThumbnailer::existing($path));
    }

    public function test_replacing_a_photo_removes_the_previous_file(): void
    {
        Notification::fake();
        Storage::fake('public');
        $news = $this->news();

        $this->actingAs($this->admin(), 'sanctum')
            ->post('/api/v1/admin/news/'.$news->id.'/image', ['photo' => UploadedFile::fake()->image('a.jpg', 900, 900)])
            ->assertOk();
        $first = $news->refresh()->image_path;

        $this->actingAs($this->admin(), 'sanctum')
            ->post('/api/v1/admin/news/'.$news->id.'/image', ['photo' => UploadedFile::fake()->image('b.jpg', 900, 900)])
            ->assertOk();

        $this->assertNotSame($first, $news->refresh()->image_path);
        Storage::disk('public')->assertMissing($first);
    }

    public function test_photo_can_be_removed(): void
    {
        Notification::fake();
        Storage::fake('public');
        $news = $this->news();

        $this->actingAs($this->admin(), 'sanctum')
            ->post('/api/v1/admin/news/'.$news->id.'/image', ['photo' => UploadedFile::fake()->image('a.jpg', 900, 900)])
            ->assertOk();
        $path = $news->refresh()->image_path;

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson('/api/v1/admin/news/'.$news->id.'/image')
            ->assertOk()
            ->assertJsonPath('data.image', null);

        Storage::disk('public')->assertMissing($path);
    }

    public function test_wrong_file_type_is_rejected_readably(): void
    {
        Notification::fake();
        $news = $this->news();

        $this->actingAs($this->admin(), 'sanctum')
            ->post('/api/v1/admin/news/'.$news->id.'/image', [
                'photo' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.photo.0', 'Такой формат не поддерживается. Подойдут JPEG, PNG или WEBP.');
    }

    public function test_deleting_news_takes_its_photo_along(): void
    {
        Notification::fake();
        Storage::fake('public');
        $news = $this->news();

        $this->actingAs($this->admin(), 'sanctum')
            ->post('/api/v1/admin/news/'.$news->id.'/image', ['photo' => UploadedFile::fake()->image('a.jpg', 900, 900)])
            ->assertOk();
        $path = $news->refresh()->image_path;

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson('/api/v1/admin/news/'.$news->id)
            ->assertOk();

        $this->assertDatabaseMissing('news', ['id' => $news->id]);
        Storage::disk('public')->assertMissing($path);
    }
}
