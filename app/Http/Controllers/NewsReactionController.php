<?php

namespace App\Http\Controllers;

use App\Enums\NewsReactionType;
use App\Models\News;
use App\Services\NewsReactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class NewsReactionController extends Controller
{
    public function store(Request $request, News $news, NewsReactionService $reactions): JsonResponse
    {
        abort_unless(
            $news->is_published && $news->published_at !== null && $news->published_at->lte(now()),
            404,
        );

        $validated = $request->validate([
            'reaction' => ['required', 'string', Rule::in(NewsReactionType::values())],
        ]);

        $type = NewsReactionType::from($validated['reaction']);

        return response()->json(
            $reactions->toggle($request->user(), $news, $type),
        );
    }
}
