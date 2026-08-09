<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Asana;
use App\Models\AsanaCategory;
use App\Models\AsanaProgram;
use App\Services\AsanaProgramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Библиотека поз и разделы (ADMIN_PLAN_2.md, фаза M).
 *
 * Отделено от `AsanaController` не по сущностям, а по сценарию: там ходят по
 * папкам и собирают занятие, здесь ищут позу и раскладывают свои зарисовки по
 * разделам. Экраны в приложении тоже разные.
 *
 * Два места, где легко ошибиться:
 *
 * - **Разделы берутся из справочника `asana_categories`, а не из самих поз.**
 *   Иначе только что созданный пустой раздел негде было бы увидеть и некуда
 *   складывать зарисовку. Название раздела при этом хранится и у позы строкой,
 *   поэтому переименование правит оба места — этим занимается сервис.
 * - **«Мои зарисовки» — не раздел, а фильтр** (`Asana::CUSTOM_CATEGORY`):
 *   своя поза может лежать в общем разделе рядом с готовыми и одновременно
 *   находиться по этому фильтру.
 */
class AsanaLibraryController extends Controller
{
    /** Больше за раз показывать незачем: в списке ищут поиском, а не прокруткой. */
    private const LIMIT = 300;

    public function __construct(private AsanaProgramService $service) {}

    /** Позы с поиском и фильтром по разделу плюс сами разделы. */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', 'string', 'max:120'],
        ]);

        $search = trim((string) ($data['q'] ?? ''));
        $category = trim((string) ($data['category'] ?? ''));

        $asanas = Asana::query()
            ->when(
                $category === Asana::CUSTOM_CATEGORY,
                fn ($q) => $q->where('is_custom', true),
                fn ($q) => $q->when($category !== '', fn ($q2) => $q2->where('category', $category)),
            )
            ->search($search)
            ->ordered()
            ->limit(self::LIMIT)
            ->get();

        return response()->json([
            'data' => $asanas->map(fn (Asana $a) => $this->asana($a))->all(),
            'categories' => $this->categories(),
            'custom_category' => Asana::CUSTOM_CATEGORY,
        ]);
    }

    /**
     * Сохранить зарисовку с холста как свою позу.
     *
     * Если пришёл `program_id` — поза сразу встаёт в конец занятия: рисуют
     * почти всегда для конкретного занятия, а не «в запас».
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'image' => ['required', 'string'],
            'name' => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', 'string', 'max:120'],
            'program_id' => ['nullable', 'integer', 'exists:asana_programs,id'],
        ], [
            'image.required' => 'Нечего сохранять — нарисуйте позу.',
        ]);

        try {
            $asana = $this->service->storeCustomAsana(
                $data['image'],
                $data['name'] ?? null,
                $data['category'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $added = false;

        if (isset($data['program_id'])) {
            $this->service->addAsana(AsanaProgram::findOrFail($data['program_id']), $asana);
            $added = true;
        }

        return response()->json([
            'data' => $this->asana($asana),
            'added_to_program' => $added,
            'message' => $added ? 'Зарисовка сохранена и добавлена в занятие.' : 'Зарисовка сохранена.',
        ], 201);
    }

    /** Переложить свою зарисовку в другой раздел библиотеки. */
    public function update(Request $request, Asana $asana): JsonResponse
    {
        $data = $request->validate([
            'category' => ['nullable', 'string', 'max:120'],
        ]);

        try {
            $asana = $this->service->setCustomAsanaCategory($asana, $data['category'] ?? null);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $this->asana($asana),
            'message' => $asana->category === null
                ? $asana->name.' — без раздела.'
                : $asana->name.' → '.$asana->category,
        ]);
    }

    /**
     * Удалить свою зарисовку.
     *
     * Позу, которая стоит в занятиях, сервис не отдаёт и возвращает их число —
     * иначе занятие молча лишилось бы шага. Показываем это как есть.
     */
    public function destroy(Asana $asana): JsonResponse
    {
        try {
            $usedIn = $this->service->deleteCustomAsana($asana);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($usedIn > 0) {
            return response()->json([
                'message' => 'Поза стоит в занятиях ('.$usedIn.') — сначала уберите её оттуда.',
                'used_in' => $usedIn,
            ], 422);
        }

        return response()->json(['message' => 'Зарисовка удалена.']);
    }

    // --- Разделы ----------------------------------------------------------

    public function storeCategory(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ], [
            'name.required' => 'Назовите раздел.',
        ]);

        $category = $this->service->createCategory($data['name']);

        if ($category === null) {
            return response()->json(['message' => 'Такой раздел уже есть.'], 422);
        }

        return response()->json([
            'categories' => $this->categories(),
            'message' => 'Раздел «'.$category->name.'» создан.',
        ], 201);
    }

    public function updateCategory(Request $request, AsanaCategory $category): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ], [
            'name.required' => 'Назовите раздел.',
        ]);

        if (! $this->service->renameCategory($category, $data['name'])) {
            return response()->json(['message' => 'Такое название уже занято.'], 422);
        }

        return response()->json([
            'categories' => $this->categories(),
            // Название раздела хранится и у поз — приложение перечитывает список.
            'name' => $category->refresh()->name,
            'message' => 'Раздел переименован.',
        ]);
    }

    /**
     * Удалить раздел. Позы остаются, но лишаются раздела: удалять вместе с
     * разделом чужие библиотечные позы было бы слишком.
     */
    public function destroyCategory(AsanaCategory $category): JsonResponse
    {
        $affected = $this->service->deleteCategory($category);

        return response()->json([
            'categories' => $this->categories(),
            'message' => $affected > 0
                ? 'Раздел удалён, поз без раздела: '.$affected.'.'
                : 'Раздел удалён.',
        ]);
    }

    // --- Сборка ответа ----------------------------------------------------

    /**
     * Разделы для фильтра и для управления.
     *
     * `id` есть только у настоящих разделов: «Мои зарисовки» — фильтр, его
     * нельзя ни переименовать, ни удалить.
     *
     * @return list<array<string, mixed>>
     */
    private function categories(): array
    {
        $rows = AsanaCategory::query()->ordered()->get()
            ->map(fn (AsanaCategory $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'count' => $c->asanaCount(),
            ])
            ->values()
            ->all();

        if (Asana::query()->where('is_custom', true)->exists()) {
            $rows[] = [
                'id' => null,
                'name' => Asana::CUSTOM_CATEGORY,
                'count' => Asana::query()->where('is_custom', true)->count(),
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function asana(Asana $asana): array
    {
        return [
            'id' => $asana->id,
            'name' => $asana->name,
            'category' => $asana->category,
            'category_label' => $asana->categoryLabel(),
            'is_custom' => $asana->is_custom,
            'image_url' => url($asana->imageUrl()),
        ];
    }
}
