<?php

namespace App\Http\Controllers;

use App\Models\News;
use Illuminate\View\View;

class NewsController extends Controller
{
    public function index(): View
    {
        $news = News::query()
            ->published()
            ->orderByDesc('published_at')
            ->paginate(9);

        return view('pages.news.index', [
            'news' => $news,
        ]);
    }

    public function show(News $news): View
    {
        abort_unless(
            $news->is_published && $news->published_at !== null && $news->published_at->lte(now()),
            404,
        );

        $more = News::query()
            ->published()
            ->whereKeyNot($news->getKey())
            ->orderByDesc('published_at')
            ->limit(3)
            ->get();

        return view('pages.news.show', [
            'item' => $news,
            'more' => $more,
        ]);
    }
}
