<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Asana;
use App\Models\AsanaFolder;
use App\Models\AsanaProgram;
use App\Models\AsanaProgramItem;
use App\Services\AsanaProgramService;
use App\Support\AsanaPrintDocument;
use App\Support\AsanaPrintLayout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Асаны и программы из приложения (ADMIN_PLAN_2.md, фаза M).
 *
 * Тонкая обёртка над `AsanaProgramService`: перестановка поз, зарисовки,
 * копирование занятия и удаление своей позы там уже написаны и проверены
 * веб-админкой. Здесь только маршруты, проверка ввода и вид ответа.
 *
 * Три вещи, которые важно не потерять при чтении:
 *
 * 1. **Своя зарисовка живёт на элементе программы, а не на позе из библиотеки.**
 *    `image_path` у `AsanaProgramItem` перекрывает картинку асаны, поэтому
 *    подпись стилусом в одном занятии не меняет позу в остальных.
 * 2. **Рисунок приезжает строкой data-URL** — ровно тем, что умеет отдать
 *    холст (в вебе canvas, в приложении Skia). Разбор и запись файла — в
 *    сервисе, здесь только перехват его исключений.
 * 3. **Печать считается на сервере.** Раскладку даёт `AsanaPrintLayout`, HTML
 *    собирает `AsanaPrintDocument`; телефон печатает готовый лист и потому не
 *    может разойтись с веб-версией.
 *
 * Библиотека поз и разделы вынесены в `AsanaLibraryController`: там своя
 * история (поиск, свои зарисовки, справочник разделов), и вместе файл вышел бы
 * на полтысячи строк.
 */
class AsanaController extends Controller
{
    public function __construct(private AsanaProgramService $service) {}

    // --- Папки и занятия -------------------------------------------------

    /** Содержимое папки: вложенные папки и занятия. Без folder — корень. */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'folder' => ['nullable', 'integer', 'exists:asana_folders,id'],
        ]);

        $folderId = isset($data['folder']) ? (int) $data['folder'] : null;
        $folder = $folderId === null ? null : AsanaFolder::find($folderId);

        return response()->json([
            'folder' => $folder === null ? null : [
                'id' => $folder->id,
                'name' => $folder->name,
                'parent_id' => $folder->parent_id,
            ],
            'breadcrumbs' => $this->breadcrumbs($folder),
            'folders' => AsanaFolder::query()
                ->where('parent_id', $folderId)
                ->ordered()
                ->get()
                ->map(fn (AsanaFolder $f) => $this->folderRow($f))
                ->all(),
            'programs' => AsanaProgram::query()
                ->where('folder_id', $folderId)
                ->ordered()
                ->withCount('items')
                ->get()
                ->map(fn (AsanaProgram $p) => [
                    'id' => $p->id,
                    'title' => $p->title,
                    'note' => $p->note,
                    'items_count' => (int) $p->items_count,
                ])
                ->all(),
        ]);
    }

    public function storeFolder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'parent_id' => ['nullable', 'integer', 'exists:asana_folders,id'],
            'name' => ['required', 'string', 'max:120'],
        ], [
            'name.required' => 'Назовите папку.',
        ]);

        $folder = AsanaFolder::create([
            'parent_id' => $data['parent_id'] ?? null,
            'name' => trim($data['name']),
        ]);

        return response()->json([
            'data' => $this->folderRow($folder),
            'message' => 'Папка создана.',
        ], 201);
    }

    public function updateFolder(Request $request, AsanaFolder $folder): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ], [
            'name.required' => 'Назовите папку.',
        ]);

        $folder->update(['name' => trim($data['name'])]);

        return response()->json([
            'data' => $this->folderRow($folder->refresh()),
            'message' => 'Папка переименована.',
        ]);
    }

    /**
     * Удалить папку.
     *
     * Вложенные папки уходят каскадом (внешний ключ), а занятия внутри —
     * открепляются и всплывают в корень: занятие ценнее папки, в которой оно
     * лежало. Поэтому в ответе честно сказано, сколько всего пропало и сколько
     * занятий теперь ищут в корне.
     */
    public function destroyFolder(AsanaFolder $folder): JsonResponse
    {
        $summary = $this->subtree($folder);
        $folder->delete();

        return response()->json([
            'message' => $summary['programs'] > 0
                ? 'Папка удалена. Занятий перенесено в корень: '.$summary['programs'].'.'
                : 'Папка удалена.',
            'freed_programs' => $summary['programs'],
        ]);
    }

    public function storeProgram(Request $request): JsonResponse
    {
        $data = $request->validate([
            'folder_id' => ['nullable', 'integer', 'exists:asana_folders,id'],
            'title' => ['required', 'string', 'max:250'],
        ], [
            'title.required' => 'Назовите занятие.',
        ]);

        $program = AsanaProgram::create([
            'folder_id' => $data['folder_id'] ?? null,
            'title' => trim($data['title']),
        ]);

        return response()->json([
            'data' => $this->program($program),
            'message' => 'Занятие создано.',
        ], 201);
    }

    /** Занятие целиком: позы по порядку и предполагаемое число листов. */
    public function showProgram(AsanaProgram $program): JsonResponse
    {
        return response()->json($this->program($program));
    }

    public function updateProgram(Request $request, AsanaProgram $program): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:250'],
            'note' => ['nullable', 'string', 'max:2000'],
        ], [
            'title.required' => 'Назовите занятие.',
        ]);

        $program->update([
            'title' => trim($data['title']),
            'note' => trim((string) ($data['note'] ?? '')) ?: null,
        ]);

        return response()->json([
            'data' => $this->program($program->refresh()),
            'message' => 'Занятие сохранено.',
        ]);
    }

    public function destroyProgram(AsanaProgram $program): JsonResponse
    {
        $program->delete();

        return response()->json(['message' => 'Занятие удалено.']);
    }

    /** Копия занятия — обычный способ сделать вариацию под человека. */
    public function duplicateProgram(AsanaProgram $program): JsonResponse
    {
        $copy = $this->service->duplicate($program);

        return response()->json([
            'data' => $this->program($copy),
            'message' => 'Создана копия занятия.',
        ], 201);
    }

    /**
     * Готовый лист для печати.
     *
     * Телефон печатает этот HTML как есть (`expo-print`) или сохраняет в PDF и
     * отправляет в «Поделиться». Раскладка та же, что у печати из веба.
     */
    public function printProgram(Request $request, AsanaProgram $program): JsonResponse
    {
        $data = $request->validate([
            'pages' => ['nullable', 'integer', 'min:0', 'max:3'],
        ]);

        $pages = (int) ($data['pages'] ?? 0);

        if ($program->items()->count() === 0) {
            return response()->json(['message' => 'В занятии нет ни одной позы.'], 422);
        }

        $document = AsanaPrintDocument::render($program, $pages);

        return response()->json([
            'html' => $document['html'],
            'layout' => $document['layout'],
            'title' => $program->title,
        ]);
    }

    // --- Позы внутри занятия ---------------------------------------------

    /** Добавить позу из библиотеки в конец занятия. */
    public function storeItem(Request $request, AsanaProgram $program): JsonResponse
    {
        $data = $request->validate([
            'asana_id' => ['required', 'integer', 'exists:asanas,id'],
        ]);

        $asana = Asana::findOrFail($data['asana_id']);
        $item = $this->service->addAsana($program, $asana);

        return response()->json([
            'data' => $this->item($item->load('asana')),
            'items' => $this->items($program->refresh()),
            'message' => $asana->name.' — добавлена.',
        ], 201);
    }

    /** Подпись к позе: счёт, дыхание, акцент. */
    public function updateItem(Request $request, AsanaProgramItem $item): JsonResponse
    {
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $item->update(['note' => trim((string) ($data['note'] ?? '')) ?: null]);

        return response()->json(['data' => $this->item($item->refresh()->load('asana'))]);
    }

    public function destroyItem(AsanaProgramItem $item): JsonResponse
    {
        $program = $item->program;
        $this->service->remove($item);

        return response()->json([
            'items' => $this->items($program),
            'message' => 'Поза убрана.',
        ]);
    }

    /**
     * Сдвинуть позу на шаг.
     *
     * Перетаскивание из веба не переносим (ADMIN_PLAN_2.md §7.2): в списке RN
     * это дорого и капризно, а в сервисе `move()` уже есть.
     */
    public function moveItem(Request $request, AsanaProgramItem $item): JsonResponse
    {
        $direction = $request->validate([
            'direction' => ['required', Rule::in(['up', 'down'])],
        ])['direction'];

        $this->service->move($item, $direction === 'up' ? -1 : 1);

        return response()->json(['items' => $this->items($item->program)]);
    }

    /**
     * Зарисовка поверх позы: библиотечная асана остаётся прежней.
     *
     * Картинка приходит строкой `data:image/png;base64,…` — тем же форматом,
     * что и из веб-холста. Проверяет и пишет её сервис.
     */
    public function storeItemDrawing(Request $request, AsanaProgramItem $item): JsonResponse
    {
        $data = $request->validate([
            'image' => ['required', 'string'],
        ], [
            'image.required' => 'Нечего сохранять — нарисуйте позу.',
        ]);

        try {
            $this->service->storeItemDrawing($item, $data['image']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $this->item($item->refresh()->load('asana')),
            'message' => 'Правка сохранена.',
        ]);
    }

    /** Вернуть исходную позу из библиотеки. */
    public function destroyItemDrawing(AsanaProgramItem $item): JsonResponse
    {
        $this->service->resetItemDrawing($item);

        return response()->json([
            'data' => $this->item($item->refresh()->load('asana')),
            'message' => 'Возвращена исходная поза.',
        ]);
    }

    // --- Сборка ответа ----------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function program(AsanaProgram $program): array
    {
        $items = $program->items()->with('asana')->get();

        return [
            'program' => [
                'id' => $program->id,
                'title' => $program->title,
                'note' => $program->note,
                'folder_id' => $program->folder_id,
            ],
            'breadcrumbs' => $this->breadcrumbs($program->folder),
            'items' => $items->map(fn (AsanaProgramItem $i) => $this->item($i))->values()->all(),
            // Сколько листов выйдет при печати «как поместится» — по этому
            // числу экран подсказывает, есть ли смысл ужимать раскладку.
            'print_pages' => AsanaPrintLayout::forItems($items)['pages'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function items(AsanaProgram $program): array
    {
        return $program->items()->with('asana')->get()
            ->map(fn (AsanaProgramItem $i) => $this->item($i))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function item(AsanaProgramItem $item): array
    {
        $path = $item->effectiveImagePath();

        return [
            'id' => $item->id,
            'asana_id' => $item->asana_id,
            'title' => $item->title(),
            'note' => $item->note,
            'position' => $item->position,
            // Ссылка абсолютная: приложение живёт на другом хосте, а холст
            // читает эту же картинку как подложку для правки.
            'image_url' => $path === null ? null : url('/'.ltrim($path, '/')),
            'ratio' => $item->aspectRatio() ?: null,
            'wide' => $item->isWideImage(),
            'edited' => $item->isEdited(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function folderRow(AsanaFolder $folder): array
    {
        $summary = $this->subtree($folder);

        return [
            'id' => $folder->id,
            'name' => $folder->name,
            'parent_id' => $folder->parent_id,
            'programs_count' => $summary['programs'],
            'folders_count' => $summary['folders'],
        ];
    }

    /**
     * Сколько всего папок и занятий внутри, считая вложенные.
     *
     * Нужно предупреждению об удалении: пальцем промахнуться легче, чем мышью,
     * и «удалить папку» должно называть цену вслух. Папок в студии десятки,
     * рекурсия здесь дешевле честного дерева в SQL.
     *
     * @return array{folders: int, programs: int}
     */
    private function subtree(AsanaFolder $folder, int $depth = 0): array
    {
        $folders = 0;
        $programs = AsanaProgram::query()->where('folder_id', $folder->id)->count();

        // Та же защита от битой иерархии, что в breadcrumbs модели.
        if ($depth < 20) {
            foreach (AsanaFolder::query()->where('parent_id', $folder->id)->get() as $child) {
                $nested = $this->subtree($child, $depth + 1);

                $folders += 1 + $nested['folders'];
                $programs += $nested['programs'];
            }
        }

        return ['folders' => $folders, 'programs' => $programs];
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function breadcrumbs(?AsanaFolder $folder): array
    {
        if ($folder === null) {
            return [];
        }

        return collect($folder->breadcrumbs())
            ->map(fn (AsanaFolder $f) => ['id' => $f->id, 'name' => $f->name])
            ->all();
    }
}
