<?php

namespace App\Http\Controllers;

use App\Models\News;
use App\Services\NewsReactionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NewsController extends Controller
{
    public function index(NewsReactionService $reactions): View
    {
        $news = News::query()
            ->published()
            ->orderByDesc('published_at')
            ->paginate(9);

        $reactionSummaries = $reactions->summariesFor($news->getCollection(), auth()->user());

        return view('pages.news.index', [
            'news' => $news,
            'reactionSummaries' => $reactionSummaries,
        ]);
    }

    public function show(Request $request, ?News $news, NewsReactionService $reactions): View|RedirectResponse
    {
        abort_if($news === null, 404);

        if (ctype_digit((string) $request->route('news'))) {
            return redirect()->route('news.show', $news, 301);
        }

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
            'reactionSummary' => $reactions->summary($news, auth()->user()),
        ]);
    }
}
