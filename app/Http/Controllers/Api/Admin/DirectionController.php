<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Direction;
use App\Services\ContentImageService;
use App\Support\DirectionMedia;
use App\Support\PhotoValidation;
use App\Support\RussianPlural;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Направления студии из приложения (ADMIN_PLAN_2.md, фаза K).
 *
 * Самая тяжёлая форма веб-админки: тексты, обложка, галерея, порядок и
 * публикация. Направления рисуют и сайт, и приложение клиента, поэтому
 * ошибка здесь видна всем сразу.
 *
 * Что важно не перепутать:
 *
 * - **Диск `public_web`, а не `public`.** Файлы направлений лежат рядом с
 *   сайтом (`public/images/directions/<slug>`), а не в `storage/app/public`,
 *   как новости и снимки тренеров. Перепутать — потерять картинки на
 *   следующем деплое.
 * - **`slug` не меняется после создания.** Он стоит в ссылках сайта, в папке
 *   с фотографиями и в `direction_slug`, по которому приложение выбирает цвет
 *   и иконку. Сервер заводит его из названия один раз.
 * - **`body` и `benefits` в базе — массивы**, а в форме это обычный текст.
 *   Разбор живёт здесь (как и в форме Filament), приложение шлёт и получает
 *   текст: абзацы через пустую строку, пункты — по строкам.
 * - **`num` («01», «02») — декор карточки на сайте.** В приложении клиента
 *   нумерацию убрали 07.08 (в боевой базе она разъехалась), но сайт её
 *   печатает, поэтому поле в форме остаётся.
 */
class DirectionController extends Controller
{
    public function __construct(
        private ContentImageService $images,
    ) {}

    /** Список направлений в порядке показа — и опубликованные, и скрытые. */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Direction::query()->ordered()->get()
                ->map(fn (Direction $d) => $this->row($d))->all(),
        ]);
    }

    public function show(Direction $direction): JsonResponse
    {
        return response()->json(['data' => $this->card($direction)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $direction = Direction::create([
            ...$this->attributes($data),
            'slug' => $this->uniqueSlug($data['title']),
            'sort_order' => (int) Direction::query()->max('sort_order') + 1,
        ]);

        return response()->json([
            'data' => $this->card($direction),
            'message' => 'Направление создано. Добавьте обложку — без неё карточка выглядит пустой.',
        ], 201);
    }

    public function update(Request $request, Direction $direction): JsonResponse
    {
        $direction->update($this->attributes($this->validated($request)));

        return response()->json([
            'data' => $this->card($direction->refresh()),
            'message' => 'Направление сохранено.',
        ]);
    }

    /**
     * Удалить направление.
     *
     * Только если на нём не висят занятия: внешний ключ стоит `nullOnDelete`,
     * то есть удаление молча оставило бы в расписании и в истории занятия без
     * направления. В вебе это массовое действие без проверок — здесь строже
     * намеренно (§7.2).
     */
    public function destroy(Direction $direction): JsonResponse
    {
        $sessions = $direction->classSessions()->count();

        if ($sessions > 0) {
            return response()->json([
                'message' => 'Так нельзя: на направлении '.$sessions.' '
                    .RussianPlural::sessions($sessions)
                    .'. Скройте его с сайта — карточка пропадёт, а история останется.',
            ], 422);
        }

        foreach ($direction->slidePaths() as $path) {
            $this->images->delete($path, 'public_web');
        }

        $direction->delete();

        return response()->json(['message' => 'Направление удалено.']);
    }

    /** Переставить направление выше или ниже соседа. */
    public function move(Request $request, Direction $direction): JsonResponse
    {
        $up = $request->validate([
            'direction' => ['required', Rule::in(['up', 'down'])],
        ])['direction'] === 'up';

        $neighbour = Direction::query()
            ->where('id', '!=', $direction->id)
            ->when(
                $up,
                fn ($q) => $q->where('sort_order', '<=', $direction->sort_order)
                    ->orderByDesc('sort_order')->orderByDesc('id'),
                fn ($q) => $q->where('sort_order', '>=', $direction->sort_order)
                    ->orderBy('sort_order')->orderBy('id'),
            )
            ->first();

        if ($neighbour === null) {
            return response()->json(['message' => 'Двигать некуда.'], 422);
        }

        DB::transaction(function () use ($direction, $neighbour): void {
            $mine = $direction->sort_order;
            $theirs = $neighbour->sort_order;

            // Порядок мог совпадать — тогда обмен ничего не изменит.
            if ($mine === $theirs) {
                $theirs = $mine + 1;
            }

            $direction->update(['sort_order' => $theirs]);
            $neighbour->update(['sort_order' => $mine]);
        });

        return response()->json([
            'data' => Direction::query()->ordered()->get()
                ->map(fn (Direction $d) => $this->row($d))->all(),
        ]);
    }

    /** Заменить обложку. Прежний файл удаляется вместе с уменьшенной копией. */
    public function storeCover(Request $request, Direction $direction): JsonResponse
    {
        $request->validate(PhotoValidation::rules(), PhotoValidation::messages(), PhotoValidation::attributes());

        try {
            $path = $this->images->store(
                $request->file(PhotoValidation::FIELD),
                'public_web',
                $this->folder($direction),
                $direction->cover_image_path,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $direction->update(['cover_image_path' => $path]);

        return response()->json([
            'data' => $this->card($direction->refresh()),
            'message' => 'Обложка сохранена.',
        ]);
    }

    /** Добавить снимок в галерею окна «Подробнее». */
    public function storeSlide(Request $request, Direction $direction): JsonResponse
    {
        $request->validate(PhotoValidation::rules(), PhotoValidation::messages(), PhotoValidation::attributes());

        try {
            $path = $this->images->store(
                $request->file(PhotoValidation::FIELD),
                'public_web',
                $this->folder($direction),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $direction->update(['gallery_paths' => [...$direction->gallery_paths ?? [], $path]]);

        return response()->json([
            'data' => $this->card($direction->refresh()),
            'message' => 'Фотография добавлена.',
        ]);
    }

    /**
     * Убрать снимок из галереи.
     *
     * Индексом, а не путём: путь — это имя файла на диске, гонять его через
     * адрес запроса значит открыть дорогу к удалению чужих файлов.
     */
    public function destroySlide(Direction $direction, int $index): JsonResponse
    {
        $gallery = array_values($direction->gallery_paths ?? []);

        if (! array_key_exists($index, $gallery)) {
            return response()->json(['message' => 'Такой фотографии уже нет.'], 422);
        }

        [$removed] = array_splice($gallery, $index, 1);

        $direction->update(['gallery_paths' => $gallery]);
        $this->images->delete($removed, 'public_web');

        return response()->json([
            'data' => $this->card($direction->refresh()),
            'message' => 'Фотография убрана.',
        ]);
    }

    /** Переставить снимок в галерее: порядок виден клиенту в карусели. */
    public function moveSlide(Request $request, Direction $direction, int $index): JsonResponse
    {
        $up = $request->validate([
            'direction' => ['required', Rule::in(['up', 'down'])],
        ])['direction'] === 'up';

        $gallery = array_values($direction->gallery_paths ?? []);
        $target = $up ? $index - 1 : $index + 1;

        if (! array_key_exists($index, $gallery) || ! array_key_exists($target, $gallery)) {
            return response()->json(['message' => 'Двигать некуда.'], 422);
        }

        [$gallery[$index], $gallery[$target]] = [$gallery[$target], $gallery[$index]];

        $direction->update(['gallery_paths' => $gallery]);

        return response()->json([
            'data' => $this->card($direction->refresh()),
            'message' => 'Порядок изменён.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'num' => ['required', 'string', 'max:4'],
            'tag' => ['nullable', 'string', 'max:120'],
            'lead' => ['required', 'string', 'max:5000'],
            'body' => ['nullable', 'string', 'max:20000'],
            'benefits' => ['nullable', 'string', 'max:5000'],
            'is_published' => ['required', 'boolean'],
        ], [
            'title.required' => 'Назовите направление.',
            'num.required' => 'Укажите номер на карточке сайта.',
            'lead.required' => 'Напишите описание — оно стоит на карточке.',
        ]);
    }

    /**
     * Поля модели из данных формы.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        return [
            'title' => $data['title'],
            'num' => $data['num'],
            'tag' => filled($data['tag'] ?? null) ? $data['tag'] : null,
            'lead' => $data['lead'],
            // Абзацы — через пустую строку, пункты — по строкам. Ровно так же
            // разбирает форма Filament; второго правила заводить нельзя.
            'body' => $this->paragraphs($data['body'] ?? null),
            'benefits' => $this->lines($data['benefits'] ?? null),
            'is_published' => (bool) $data['is_published'],
        ];
    }

    /**
     * @return list<string>
     */
    private function paragraphs(?string $text): array
    {
        if (! filled($text)) {
            return [];
        }

        return array_values(array_filter(array_map(trim(...), preg_split('/\R\R+/u', $text) ?: [])));
    }

    /**
     * @return list<string>
     */
    private function lines(?string $text): array
    {
        if (! filled($text)) {
            return [];
        }

        return array_values(array_filter(array_map(trim(...), preg_split('/\R/u', $text) ?: [])));
    }

    /**
     * Код направления из названия.
     *
     * Латиницей и один раз за жизнь: он стоит в ссылках сайта, в имени папки с
     * фотографиями и в `direction_slug`, по которому приложение выбирает цвет и
     * иконку. Вводить его руками с телефона незачем — название уже есть.
     */
    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title, '-', 'ru') ?: 'napravlenie';
        $slug = $base;
        $n = 2;

        while (Direction::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }

    private function folder(Direction $direction): string
    {
        return 'images/directions/'.$direction->slug;
    }

    /**
     * Строка списка.
     *
     * @return array<string, mixed>
     */
    private function row(Direction $direction): array
    {
        return [
            'id' => $direction->id,
            'slug' => $direction->slug,
            'title' => $direction->title,
            'tag' => $direction->tag,
            'sort_order' => $direction->sort_order,
            'is_published' => $direction->is_published,
            'cover' => $direction->coverUrl(),
            'slides' => count($direction->gallery_paths ?? []),
            'sessions' => $direction->classSessions()->count(),
        ];
    }

    /**
     * Карточка для формы: тексты уже склеены обратно в один кусок.
     *
     * @return array<string, mixed>
     */
    private function card(Direction $direction): array
    {
        return [
            ...$this->row($direction),
            'num' => $direction->num,
            'lead' => $direction->lead,
            'body' => implode("\n\n", array_filter($direction->body ?? [])),
            'benefits' => implode("\n", array_filter($direction->benefits ?? [])),
            'gallery' => collect($direction->gallery_paths ?? [])
                ->values()
                ->map(fn (string $path, int $i) => [
                    'index' => $i,
                    'url' => DirectionMedia::url($path),
                ])
                ->all(),
        ];
    }
}
