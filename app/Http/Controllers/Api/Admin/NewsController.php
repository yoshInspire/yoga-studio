<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\News;
use App\Services\ContentImageService;
use App\Services\NewsNotificationService;
use App\Support\PhotoValidation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Новости студии из приложения.
 *
 * Тонкая обёртка: подписи, миниатюры и рассылка клиентам уже висят на модели
 * и `NewsObserver`, здесь только приём данных.
 *
 * **Главное, что должно быть видно снаружи: сохранение опубликованной новости
 * рассылает письма и Telegram всем клиентам с принятой офертой.** Делает это
 * `NewsObserver` → `NewsNotificationService`, один раз на новость
 * (`notifications_sent_at`). Поэтому в ответах есть `will_notify` и число
 * получателей: приложение обязано предупредить до нажатия, а не после.
 *
 * Slug наружу не отдаётся на правку намеренно. Модель заводит его сама из
 * заголовка при создании и больше не трогает: он стоит в ссылках, которые уже
 * ушли клиентам в письмах и пушах (`payload.news_slug`).
 */
class NewsController extends Controller
{
    private const PER_PAGE = 20;

    public function __construct(
        private ContentImageService $images,
        private NewsNotificationService $notifications,
    ) {}

    /** Список с фильтром по состоянию и поиском по заголовку. */
    public function index(Request $request): JsonResponse
    {
        $status = (string) $request->query('status', '');
        $search = trim((string) $request->query('q', ''));

        $query = News::query()->withCount('reactions');

        if ($status === 'published') {
            $query->published();
        } elseif ($status === 'draft') {
            // Черновик — всё, что клиент сейчас не видит: снятое с публикации
            // и запланированное на будущее.
            $query->where(function ($q) {
                $q->where('is_published', false)
                    ->orWhereNull('published_at')
                    ->orWhere('published_at', '>', now());
            });
        }

        if ($search !== '') {
            $query->where('title', 'like', '%'.$search.'%');
        }

        $page = $query
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);

        return response()->json([
            'data' => collect($page->items())->map(fn (News $n) => $this->row($n))->all(),
            'meta' => array_merge(PaymentController::meta($page), [
                'notify_recipients' => $this->recipients(),
            ]),
        ]);
    }

    /** Одна новость целиком — для формы правки. */
    public function show(News $news): JsonResponse
    {
        return response()->json(['data' => $this->full($news)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $news = new News;
        $news->fill($data);
        $notified = $this->saveAndReportNotifications($news);

        return response()->json([
            'data' => $this->full($news),
            'notified' => $notified,
            'message' => $notified === null
                ? 'Новость сохранена.'
                : 'Новость опубликована, уведомления отправлены: '.$notified.'.',
        ], 201);
    }

    public function update(Request $request, News $news): JsonResponse
    {
        $news->fill($this->validated($request));
        $notified = $this->saveAndReportNotifications($news);

        return response()->json([
            'data' => $this->full($news),
            'notified' => $notified,
            'message' => $notified === null
                ? 'Новость сохранена.'
                : 'Новость опубликована, уведомления отправлены: '.$notified.'.',
        ]);
    }

    public function destroy(News $news): JsonResponse
    {
        // Реакции уходят каскадом (FK в миграции), а картинку с её уменьшенной
        // копией за нами никто не уберёт.
        $this->images->delete($news->image_path, 'public');
        $news->delete();

        return response()->json(['message' => 'Новость удалена.']);
    }

    /** Поставить или заменить снимок. */
    public function storeImage(Request $request, News $news): JsonResponse
    {
        $request->validate(
            PhotoValidation::rules(),
            PhotoValidation::messages(),
            PhotoValidation::attributes(),
        );

        try {
            $path = $this->images->store($request->file('photo'), 'public', 'news', $news->image_path);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Через модель, а не forceFill+saveQuietly: уменьшенную копию заводит
        // NewsObserver на событии saved.
        $news->update(['image_path' => $path]);

        return response()->json([
            'data' => $this->full($news->refresh()),
            'message' => 'Фотография сохранена.',
        ]);
    }

    public function destroyImage(News $news): JsonResponse
    {
        if ($news->image_path === null) {
            return response()->json(['message' => 'Фотографии и так нет.'], 422);
        }

        $previous = $news->image_path;
        $news->update(['image_path' => null]);
        $this->images->delete($previous, 'public');

        return response()->json([
            'data' => $this->full($news->refresh()),
            'message' => 'Фотография удалена.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'body' => ['required', 'string', 'max:20000'],
            'is_published' => ['required', 'boolean'],
            'published_at' => ['nullable', 'date'],
        ], [
            'title.required' => 'Напишите заголовок.',
            'body.required' => 'Напишите текст новости.',
            'excerpt.max' => 'Краткое описание не длиннее 500 символов.',
        ]);

        // Опубликованная новость без даты никогда не покажется клиенту:
        // `scopePublished` требует published_at. В админке дату подставляет
        // форма, здесь — сервер, чтобы «Опубликовать» на телефоне значило
        // именно опубликовать.
        if (($data['is_published'] ?? false) && blank($data['published_at'] ?? null)) {
            $data['published_at'] = now();
        }

        return $data;
    }

    /**
     * Сохранить и сказать, ушла ли рассылка.
     *
     * Отметку ставит наблюдатель уже после save(), поэтому сравниваем «было и
     * стало»: молчать о том, что письма ушли шестидесяти восьми клиентам,
     * нельзя.
     *
     * @return int|null число получателей либо null, если рассылки не было
     */
    private function saveAndReportNotifications(News $news): ?int
    {
        $sentBefore = $news->notifications_sent_at;
        // Считаем до сохранения: после него получатели те же, но запрос дешевле
        // держать в одном месте, чем повторять условие рассылки.
        $recipients = $this->recipients();

        $news->save();
        $news->refresh();

        return $sentBefore === null && $news->notifications_sent_at !== null
            ? $recipients
            : null;
    }

    /** Сколько клиентов получат уведомление — считает сам сервис рассылки. */
    private function recipients(): int
    {
        return $this->notifications->recipientsCount();
    }

    /**
     * Строка списка.
     *
     * @return array<string, mixed>
     */
    private function row(News $news): array
    {
        return [
            'id' => $news->id,
            'slug' => $news->slug,
            'title' => $news->title,
            'excerpt' => $news->readableExcerpt(),
            'image' => $news->imageUrl(),
            'image_thumb' => $news->imageThumbUrl(),
            'image_ratio' => $news->imageRatio(),
            'is_published' => (bool) $news->is_published,
            'published_at' => $news->published_at?->toIso8601String(),
            'date' => $news->formattedDate(),
            'state' => $this->state($news),
            'notified' => $news->notifications_sent_at !== null,
            'reactions' => (int) ($news->reactions_count ?? 0),
        ];
    }

    /**
     * Строка списка плюс всё, что нужно форме.
     *
     * @return array<string, mixed>
     */
    private function full(News $news): array
    {
        return array_merge($this->row($news), [
            'body' => $news->body,
            'excerpt_raw' => $news->excerpt,
            // Уйдёт ли рассылка, если сохранить как есть. Приложение спрашивает
            // подтверждение именно по этому полю.
            'will_notify' => $this->notifications->shouldNotify($news),
        ]);
    }

    /** Человеческое состояние для бейджа в списке. */
    private function state(News $news): string
    {
        if (! $news->is_published) {
            return 'draft';
        }

        if ($news->published_at === null) {
            return 'draft';
        }

        return $news->published_at->gt(now()) ? 'scheduled' : 'published';
    }
}
