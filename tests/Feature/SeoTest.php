<?php

namespace Tests\Feature;

use App\Models\Direction;
use App\Models\News;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\DirectionSeeder::class);
    }

    public function test_home_page_includes_seo_tags_and_local_business_schema(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('<link rel="canonical"', false);
        $response->assertSee('property="og:title"', false);
        $response->assertSee('"@type":"YogaStudio"', false);
        $response->assertSee('Коньково', false);
    }

    public function test_sitemap_lists_public_pages(): void
    {
        $news = News::query()->create([
            'title' => 'Открытие сезона',
            'body' => 'Текст новости.',
            'is_published' => true,
            'published_at' => now()->subHour(),
        ]);

        $direction = Direction::query()->published()->firstOrFail();

        $response = $this->get(route('sitemap'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        $response->assertSee(route('home'), false);
        $response->assertSee(route('schedule'), false);
        $response->assertSee(route('directions'), false);
        $response->assertSee(route('news.index'), false);
        $response->assertSee(route('news.show', $news), false);
        $response->assertSee(route('directions.show', $direction), false);
    }

    public function test_login_page_is_not_indexed(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk();
        $response->assertSee('name="robots" content="noindex, nofollow"', false);
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_news_show_redirects_legacy_numeric_url_to_slug(): void
    {
        $news = News::query()->create([
            'title' => 'Йога-ретрит в студии',
            'body' => 'Подробности события.',
            'is_published' => true,
            'published_at' => now()->subHour(),
        ]);

        $this->get('/news/'.$news->id)
            ->assertRedirect(route('news.show', $news));
    }

    public function test_direction_show_page_is_public_and_has_breadcrumbs_schema(): void
    {
        $direction = Direction::query()->published()->where('slug', 'hatha')->firstOrFail();

        $response = $this->get(route('directions.show', $direction));

        $response->assertOk();
        $response->assertSee('<h1', false);
        $response->assertSee($direction->title, false);
        $response->assertSee('"@type":"BreadcrumbList"', false);
    }

    public function test_schedule_page_uses_canonical_without_query_parameters(): void
    {
        $response = $this->get(route('schedule', ['offset' => 2]));

        $response->assertOk();
        $response->assertSee(
            '<link rel="canonical" href="'.route('schedule').'"',
            false,
        );
    }
}
