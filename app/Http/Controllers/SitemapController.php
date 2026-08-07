<?php

namespace App\Http\Controllers;

use App\Models\Direction;
use App\Models\News;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    public function __invoke(): Response
    {
        $urls = [
            $this->entry(route('home'), 'weekly', '1.0'),
            $this->entry(route('schedule'), 'daily', '0.9'),
            $this->entry(route('directions'), 'monthly', '0.8'),
            $this->entry(route('news.index'), 'weekly', '0.7'),
            // Правовые документы: их адреса указаны в карточках App Store и
            // Google Play, поэтому страницы должны быть видимыми и живыми.
            $this->entry(route('legal.offer'), 'yearly', '0.3'),
            $this->entry(route('legal.privacy'), 'yearly', '0.3'),
        ];

        Direction::query()
            ->published()
            ->ordered()
            ->get(['slug', 'updated_at'])
            ->each(function (Direction $direction) use (&$urls): void {
                $urls[] = $this->entry(
                    route('directions.show', $direction),
                    'monthly',
                    '0.7',
                    $direction->updated_at,
                );
            });

        News::query()
            ->published()
            ->orderByDesc('published_at')
            ->get(['slug', 'updated_at', 'published_at'])
            ->each(function (News $news) use (&$urls): void {
                $urls[] = $this->entry(
                    route('news.show', $news),
                    'monthly',
                    '0.6',
                    $news->updated_at ?? $news->published_at,
                );
            });

        return response()
            ->view('sitemap', ['urls' => $urls])
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    /**
     * @return array{loc: string, changefreq: string, priority: string, lastmod?: string}
     */
    private function entry(string $loc, string $changefreq, string $priority, mixed $lastmod = null): array
    {
        $entry = [
            'loc' => $loc,
            'changefreq' => $changefreq,
            'priority' => $priority,
        ];

        if ($lastmod !== null) {
            $entry['lastmod'] = $lastmod->toAtomString();
        }

        return $entry;
    }
}
