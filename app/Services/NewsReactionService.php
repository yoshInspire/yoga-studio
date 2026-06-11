<?php

namespace App\Services;

use App\Enums\NewsReactionType;
use App\Models\News;
use App\Models\NewsReaction;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class NewsReactionService
{
    /**
     * @return array{
     *     counts: array<string, int>,
     *     total: int,
     *     user_reaction: ?string,
     * }
     */
    public function toggle(User $user, News $news, NewsReactionType $type): array
    {
        $existing = NewsReaction::query()
            ->where('news_id', $news->getKey())
            ->where('user_id', $user->getKey())
            ->first();

        if ($existing !== null && $existing->type === $type) {
            $existing->delete();
        } elseif ($existing !== null) {
            $existing->update(['type' => $type]);
        } else {
            NewsReaction::query()->create([
                'news_id' => $news->getKey(),
                'user_id' => $user->getKey(),
                'type' => $type,
            ]);
        }

        return $this->summary($news, $user);
    }

    /**
     * @return array{
     *     counts: array<string, int>,
     *     total: int,
     *     user_reaction: ?string,
     * }
     */
    public function summary(News $news, ?User $user = null): array
    {
        $counts = array_fill_keys(NewsReactionType::values(), 0);

        NewsReaction::query()
            ->where('news_id', $news->getKey())
            ->selectRaw('type, COUNT(*) as aggregate')
            ->groupBy('type')
            ->get()
            ->each(function (NewsReaction $row) use (&$counts): void {
                $type = $row->type instanceof NewsReactionType ? $row->type->value : (string) $row->type;

                if (array_key_exists($type, $counts)) {
                    $counts[$type] = (int) $row->aggregate;
                }
            });

        $userReaction = null;

        if ($user !== null) {
            $userReaction = NewsReaction::query()
                ->where('news_id', $news->getKey())
                ->where('user_id', $user->getKey())
                ->value('type');

            $userReaction = $userReaction instanceof NewsReactionType
                ? $userReaction->value
                : $userReaction;
        }

        return [
            'counts' => $counts,
            'total' => array_sum($counts),
            'user_reaction' => $userReaction,
        ];
    }

    /**
     * @param  Collection<int, News>  $newsItems
     * @return array<int, array{
     *     counts: array<string, int>,
     *     total: int,
     *     user_reaction: ?string,
     * }>
     */
    public function summariesFor(Collection $newsItems, ?User $user = null): array
    {
        if ($newsItems->isEmpty()) {
            return [];
        }

        $newsIds = $newsItems->pluck('id');

        $counts = array_fill_keys(NewsReactionType::values(), 0);
        $byNews = [];

        foreach ($newsIds as $newsId) {
            $byNews[$newsId] = [
                'counts' => $counts,
                'total' => 0,
                'user_reaction' => null,
            ];
        }

        DB::table('news_reactions')
            ->whereIn('news_id', $newsIds)
            ->select('news_id', 'type', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('news_id', 'type')
            ->get()
            ->each(function ($row) use (&$byNews): void {
                $newsId = (int) $row->news_id;
                $type = (string) $row->type;

                if (! isset($byNews[$newsId]) || ! array_key_exists($type, $byNews[$newsId]['counts'])) {
                    return;
                }

                $byNews[$newsId]['counts'][$type] = (int) $row->aggregate;
                $byNews[$newsId]['total'] += (int) $row->aggregate;
            });

        if ($user !== null) {
            NewsReaction::query()
                ->whereIn('news_id', $newsIds)
                ->where('user_id', $user->getKey())
                ->get(['news_id', 'type'])
                ->each(function (NewsReaction $row) use (&$byNews): void {
                    $newsId = (int) $row->news_id;

                    if (! isset($byNews[$newsId])) {
                        return;
                    }

                    $byNews[$newsId]['user_reaction'] = $row->type instanceof NewsReactionType
                        ? $row->type->value
                        : (string) $row->type;
                });
        }

        return $byNews;
    }
}
