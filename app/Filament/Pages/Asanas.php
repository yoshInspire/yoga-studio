<?php

namespace App\Filament\Pages;

use App\Models\Asana;
use App\Models\AsanaFolder;
use App\Models\AsanaProgram;
use App\Models\AsanaProgramItem;
use App\Services\AsanaProgramService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class Asanas extends Page
{
    protected static ?string $navigationLabel = 'Асаны';

    protected static ?string $title = 'Асаны и программы';

    protected static ?int $navigationSort = 8;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPencilSquare;

    protected string $view = 'filament.pages.asanas';

    /** folders | program | library */
    public string $mode = 'folders';

    public ?int $folderId = null;

    public ?int $programId = null;

    public string $search = '';

    public ?string $categoryFilter = null;

    public string $newFolderName = '';

    public string $newProgramTitle = '';

    /** null | 'new' (зарисовать позу с нуля) | 'item' (поправить позу в занятии) */
    public ?string $drawingMode = null;

    /** Элемент программы, который сейчас правится стилусом. */
    public ?int $drawingItemId = null;

    public function mount(): void
    {
        $this->mode = 'folders';
    }

    // --- Навигация -----------------------------------------------------

    public function openFolder(?int $id = null): void
    {
        $this->folderId = $id;
        $this->programId = null;
        $this->mode = 'folders';
    }

    public function openProgram(int $id): void
    {
        $program = AsanaProgram::find($id);

        if ($program === null) {
            $this->openFolder($this->folderId);

            return;
        }

        $this->programId = $program->id;
        $this->folderId = $program->folder_id;
        $this->mode = 'program';
    }

    public function openLibrary(): void
    {
        if ($this->programId === null) {
            return;
        }

        $this->mode = 'library';
    }

    public function backToProgram(): void
    {
        $this->mode = $this->programId === null ? 'folders' : 'program';
        $this->search = '';
    }

    // --- Папки ---------------------------------------------------------

    public function createFolder(): void
    {
        $name = trim($this->newFolderName);

        if ($name === '') {
            return;
        }

        AsanaFolder::create([
            'parent_id' => $this->folderId,
            'name' => $name,
        ]);

        $this->newFolderName = '';
        $this->notify('Папка создана');
    }

    public function renameFolder(int $id, string $name): void
    {
        $name = trim($name);
        $folder = AsanaFolder::find($id);

        if ($folder === null || $name === '') {
            return;
        }

        $folder->update(['name' => $name]);
        $this->notify('Папка переименована');
    }

    public function deleteFolder(int $id): void
    {
        $folder = AsanaFolder::find($id);

        if ($folder === null) {
            return;
        }

        // Вложенные папки уходят каскадом, программы внутри — открепляются.
        $folder->delete();
        $this->notify('Папка удалена');
    }

    // --- Программы -----------------------------------------------------

    public function createProgram(): void
    {
        $title = trim($this->newProgramTitle);

        if ($title === '') {
            return;
        }

        $program = AsanaProgram::create([
            'folder_id' => $this->folderId,
            'title' => $title,
        ]);

        $this->newProgramTitle = '';
        $this->openProgram($program->id);
        $this->notify('Занятие создано');
    }

    public function renameProgram(int $id, string $title): void
    {
        $title = trim($title);
        $program = AsanaProgram::find($id);

        if ($program === null || $title === '') {
            return;
        }

        $program->update(['title' => $title]);
        $this->notify('Название изменено');
    }

    public function saveProgramNote(string $note): void
    {
        $this->currentProgram()?->update(['note' => trim($note) ?: null]);
        $this->notify('Заметка сохранена');
    }

    public function deleteProgram(int $id): void
    {
        $program = AsanaProgram::find($id);

        if ($program === null) {
            return;
        }

        $folderId = $program->folder_id;
        $program->delete();

        $this->programId = null;
        $this->openFolder($folderId);
        $this->notify('Занятие удалено');
    }

    public function duplicateProgram(int $id): void
    {
        $program = AsanaProgram::find($id);

        if ($program === null) {
            return;
        }

        $copy = app(AsanaProgramService::class)->duplicate($program);

        $this->openProgram($copy->id);
        $this->notify('Создана копия занятия');
    }

    // --- Позы в программе ----------------------------------------------

    public function addAsana(int $asanaId): void
    {
        $program = $this->currentProgram();
        $asana = Asana::find($asanaId);

        if ($program === null || $asana === null) {
            return;
        }

        app(AsanaProgramService::class)->addAsana($program, $asana);
        $this->notify($asana->name.' — добавлена');
    }

    public function removeItem(int $itemId): void
    {
        $item = $this->findItem($itemId);

        if ($item === null) {
            return;
        }

        app(AsanaProgramService::class)->remove($item);
        $this->notify('Поза убрана');
    }

    public function moveItem(int $itemId, int $direction): void
    {
        $item = $this->findItem($itemId);

        if ($item === null) {
            return;
        }

        app(AsanaProgramService::class)->move($item, $direction);
    }

    /** @param  list<int|string>  $orderedIds */
    public function reorderItems(array $orderedIds): void
    {
        $program = $this->currentProgram();

        if ($program === null) {
            return;
        }

        app(AsanaProgramService::class)->reorder(
            $program,
            array_map('intval', $orderedIds),
        );
    }

    public function saveItemNote(int $itemId, string $note): void
    {
        $this->findItem($itemId)?->update(['note' => trim($note) ?: null]);
    }

    // --- Рисование ------------------------------------------------------

    public function startNewDrawing(): void
    {
        $this->drawingMode = 'new';
        $this->drawingItemId = null;
    }

    public function startItemDrawing(int $itemId): void
    {
        if ($this->findItem($itemId) === null) {
            return;
        }

        $this->drawingMode = 'item';
        $this->drawingItemId = $itemId;
    }

    public function stopDrawing(): void
    {
        $this->drawingMode = null;
        $this->drawingItemId = null;
    }

    /** Новая поза с нуля — попадает в личную библиотеку и в конец программы. */
    public function saveNewDrawing(string $dataUrl, string $name = '', string $category = ''): void
    {
        $service = app(AsanaProgramService::class);

        try {
            $asana = $service->storeCustomAsana($dataUrl, $name, $category);
        } catch (InvalidArgumentException $e) {
            $this->notify($e->getMessage(), danger: true);

            return;
        }

        $program = $this->currentProgram();

        if ($program !== null) {
            $service->addAsana($program, $asana);
            $this->mode = 'program';
        }

        $this->drawingMode = null;
        $this->drawingItemId = null;
        $this->notify('Зарисовка сохранена');
    }

    /** Правка позы внутри занятия: библиотека остаётся нетронутой. */
    public function saveItemDrawing(int $itemId, string $dataUrl): void
    {
        $item = $this->findItem($itemId);

        if ($item === null) {
            return;
        }

        try {
            app(AsanaProgramService::class)->storeItemDrawing($item, $dataUrl);
        } catch (InvalidArgumentException $e) {
            $this->notify($e->getMessage(), danger: true);

            return;
        }

        $this->drawingMode = null;
        $this->drawingItemId = null;
        $this->notify('Правка сохранена');
    }

    public function resetItemDrawing(int $itemId): void
    {
        $item = $this->findItem($itemId);

        if ($item === null) {
            return;
        }

        app(AsanaProgramService::class)->resetItemDrawing($item);
        $this->notify('Возвращена исходная поза');
    }

    /** Переложить свою зарисовку в раздел библиотеки. */
    public function setAsanaCategory(int $asanaId, string $category = ''): void
    {
        $asana = Asana::find($asanaId);

        if ($asana === null || ! $asana->is_custom) {
            return;
        }

        $asana = app(AsanaProgramService::class)->setCustomAsanaCategory($asana, $category);

        $this->notify($asana->category === null
            ? $asana->name.' — без раздела'
            : $asana->name.' → '.$asana->category);
    }

    public function deleteCustomAsana(int $asanaId): void
    {
        $asana = Asana::find($asanaId);

        if ($asana === null || ! $asana->is_custom) {
            return;
        }

        $usedIn = app(AsanaProgramService::class)->deleteCustomAsana($asana);

        if ($usedIn > 0) {
            $this->notify(
                'Поза стоит в занятиях ('.$usedIn.') — сначала уберите её оттуда.',
                danger: true,
            );

            return;
        }

        $this->notify('Зарисовка удалена');
    }

    // --- Данные для вида -------------------------------------------------

    public function getViewData(): array
    {
        return match ($this->mode) {
            'program' => $this->programViewData(),
            'library' => $this->libraryViewData(),
            default => $this->foldersViewData(),
        };
    }

    private function foldersViewData(): array
    {
        $folder = $this->currentFolder();

        return [
            'folder' => $folder,
            'breadcrumbs' => $folder?->breadcrumbs() ?? [],
            'folders' => AsanaFolder::query()
                ->where('parent_id', $this->folderId)
                ->ordered()
                ->withCount('programs')
                ->get(),
            'programs' => AsanaProgram::query()
                ->where('folder_id', $this->folderId)
                ->ordered()
                ->withCount('items')
                ->get(),
        ];
    }

    private function programViewData(): array
    {
        $program = $this->currentProgram();

        return [
            'program' => $program,
            'items' => $program?->items()->with('asana')->get() ?? collect(),
            'breadcrumbs' => $program?->folder?->breadcrumbs() ?? [],
            'drawingItem' => $this->drawingItemId === null
                ? null
                : $this->findItem($this->drawingItemId),
            // Нужны окну рисования: куда положить новую позу.
            'libraryCategories' => app(AsanaProgramService::class)->libraryCategories(),
        ];
    }

    private function libraryViewData(): array
    {
        return [
            'program' => $this->currentProgram(),
            'categories' => $this->categories(),
            // Разделы, куда можно положить свою зарисовку.
            'libraryCategories' => app(AsanaProgramService::class)->libraryCategories(),
            'asanas' => Asana::query()
                ->when(
                    $this->categoryFilter === Asana::CUSTOM_CATEGORY,
                    fn ($q) => $q->where('is_custom', true),
                    fn ($q) => $q->when(
                        filled($this->categoryFilter),
                        fn ($q2) => $q2->where('category', $this->categoryFilter),
                    ),
                )
                ->search($this->search)
                ->ordered()
                ->limit(300)
                ->get(),
        ];
    }

    /** @return Collection<int, string> */
    private function categories(): Collection
    {
        $categories = Asana::query()
            ->whereNotNull('category')
            ->where('is_custom', false)
            ->distinct()
            ->orderBy('category')
            ->pluck('category');

        if (Asana::query()->where('is_custom', true)->exists()) {
            $categories->push(Asana::CUSTOM_CATEGORY);
        }

        return $categories;
    }

    public function currentFolder(): ?AsanaFolder
    {
        return $this->folderId === null ? null : AsanaFolder::find($this->folderId);
    }

    public function currentProgram(): ?AsanaProgram
    {
        return $this->programId === null ? null : AsanaProgram::find($this->programId);
    }

    private function findItem(int $itemId): ?AsanaProgramItem
    {
        return AsanaProgramItem::query()
            ->where('id', $itemId)
            ->where('program_id', $this->programId)
            ->first();
    }

    private function notify(string $message, bool $danger = false): void
    {
        $notification = Notification::make()->title($message);

        $danger ? $notification->danger() : $notification->success();

        $notification->send();
    }
}
