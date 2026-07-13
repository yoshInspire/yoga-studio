<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Direction;
use App\Models\News;
use App\Services\BookingService;
use App\Support\DirectionMedia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContentController extends Controller
{
    /** Список направлений (публично). */
    public function directions(): JsonResponse
    {
        $items = Direction::query()->published()->ordered()->get()
            ->map(fn (Direction $d) => [
                'id' => $d->id,
                'slug' => $d->slug,
                'num' => $d->num,
                'title' => $d->title,
                'tag' => $d->tag,
                'lead' => $d->lead,
                'cover' => $d->coverUrl(),
            ]);

        return response()->json(['data' => $items]);
    }

    /** Детальная карточка направления. */
    public function direction(string $slug): JsonResponse
    {
        $d = Direction::query()->published()->where('slug', $slug)->firstOrFail();

        return response()->json([
            'data' => [
                'id' => $d->id,
                'slug' => $d->slug,
                'num' => $d->num,
                'title' => $d->title,
                'tag' => $d->tag,
                'lead' => $d->lead,
                'cover' => $d->coverUrl(),
                'slides' => collect($d->slidePaths())
                    ->map(fn ($p) => DirectionMedia::url($p))
                    ->filter()
                    ->values(),
                'body' => array_values($d->body ?? []),
                'benefits' => array_values($d->benefits ?? []),
            ],
        ]);
    }

    /** Лента новостей (опубликованные). */
    public function news(): JsonResponse
    {
        $items = News::query()->published()->orderByDesc('published_at')->get()
            ->map(fn (News $n) => [
                'id' => $n->id,
                'slug' => $n->slug,
                'title' => $n->title,
                'excerpt' => $n->readableExcerpt(),
                'image' => $n->imageUrl(),
                'date' => $n->formattedDate(),
            ]);

        return response()->json(['data' => $items]);
    }

    /** Одна новость. */
    public function newsItem(string $slug): JsonResponse
    {
        $n = News::query()->published()->where('slug', $slug)->firstOrFail();

        return response()->json([
            'data' => [
                'id' => $n->id,
                'slug' => $n->slug,
                'title' => $n->title,
                'body' => $n->body,
                'image' => $n->imageUrl(),
                'date' => $n->formattedDate(),
            ],
        ]);
    }

    /**
     * Расписание (скользящее окно на 7 дней от смещения).
     * Доступно и гостю, и клиенту — для клиента добавляются флаги записи.
     */
    public function schedule(Request $request, BookingService $bookings): JsonResponse
    {
        $offset = $bookings->scheduleOffset($request->query('offset'));
        $startDate = $bookings->scheduleStart($offset);
        $viewer = auth('sanctum')->user();

        $days = $bookings->buildRollingSchedule($startDate, $viewer);

        return response()->json([
            'offset' => $offset,
            'can_go_prev' => $offset > 0,
            'prev_offset' => max(0, $offset - 1),
            'next_offset' => $offset + 1,
            'range_label' => \App\Support\RussianDate::dayMonthRange($startDate, $startDate->copy()->addDays(6)),
            'days' => $days,
        ]);
    }
}
