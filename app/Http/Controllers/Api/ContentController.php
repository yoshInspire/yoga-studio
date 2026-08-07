<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Direction;
use App\Models\News;
use App\Services\BookingService;
use App\Support\DirectionMedia;
use App\Support\PricingDisplay;
use App\Support\LegalDocuments;
use App\Support\StudioRules;
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
                // Приложение показывает картинки в карточках, а не во всю
                // страницу, и грузило бы оригиналы по мегабайту. Оригинал
                // оставлен в ответе: он нужен сайту и старым версиям приложения.
                'image_thumb' => $n->imageThumbUrl(),
                // Форма снимка: приложение подгоняет бокс под неё, иначе
                // квадратные кадры из инстаграма режутся в горизонтальных
                // карточках вместе с головами и вывеской студии.
                'image_ratio' => $n->imageRatio(),
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
                'image_thumb' => $n->imageThumbUrl(),
                'image_ratio' => $n->imageRatio(),
                'date' => $n->formattedDate(),
            ],
        ]);
    }

    /**
     * Цены (публично) — то же, что в блоке «Услуги и цены» на главной сайта.
     *
     * Прайс собирается из config/pricing.php и каталога в админке, поэтому
     * приложение обязано получать его с сервера, а не хранить у себя.
     */
    public function pricing(): JsonResponse
    {
        $blocks = collect(PricingDisplay::blocks())
            ->map(fn (array $block, string $key) => [
                'key' => $key,
                'title' => $block['title'],
                'sections' => array_map(fn (array $section) => [
                    'title' => $section['title'] ?? null,
                    'items' => array_map(fn (array $item) => [
                        'name' => $item['name'],
                        'price' => (int) ($item['price'] ?? 0),
                        'highlight' => (bool) ($item['highlight'] ?? false),
                    ], $section['items']),
                ], $block['sections']),
                'notes' => array_values($block['notes']),
            ])
            ->values();

        return response()->json(['data' => $blocks]);
    }

    /** Правила студии (публично) — тот же текст, что в FAQ на странице расписания. */
    public function rules(): JsonResponse
    {
        return response()->json([
            'lead' => StudioRules::lead(),
            'data' => StudioRules::plain(),
        ]);
    }

    /**
     * Правовые документы для экрана «Документы».
     *
     * Приложение получает адреса с сервера и не хранит их у себя: сменится
     * домен или появится новый документ — обновлять сборку не придётся.
     */
    public function legal(): JsonResponse
    {
        return response()->json(['data' => LegalDocuments::items()]);
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
