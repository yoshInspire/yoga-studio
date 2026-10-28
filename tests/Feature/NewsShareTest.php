<?php

namespace Tests\Feature;

use App\Models\News;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Кнопки «Поделитесь новостью» на странице новости.
 */
class NewsShareTest extends TestCase
{
    use RefreshDatabase;

    public function test_news_page_offers_sharing_with_title_and_link(): void
    {
        $news = News::query()->create([
            'title' => 'Йога для детей & взрослых',
            'body' => 'Запишитесь прямо сейчас.',
            'is_published' => true,
            'published_at' => now()->subHour(),
        ]);
        $url = route('news.show', $news);

        $html = $this->get($url)->assertOk()->getContent();

        $this->assertStringContainsString('Поделитесь новостью с друзьями', $html);
        $this->assertStringContainsString('https://t.me/share/url?url='.rawurlencode($url), $html);
        $this->assertStringContainsString('https://wa.me/?text='.rawurlencode($news->title."\n".$url), $html);
        $this->assertStringContainsString('mailto:?subject='.rawurlencode($news->title), $html);
        $this->assertStringContainsString('sms:?&amp;body=', $html);
        $this->assertStringContainsString('data-share-copy', $html);
    }
}
