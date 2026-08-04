<?php

namespace App\Services;

use App\Models\Asana;
use App\Models\AsanaCategory;
use App\Models\AsanaProgram;
use App\Models\AsanaProgramItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AsanaProgramService
{
    /** Куда складываются свои зарисовки относительно public/. */
    public const CUSTOM_DIR = 'images/asanas/custom';

    /** Разумный потолок для картинки из холста. */
    private const MAX_IMAGE_BYTES = 4 * 1024 * 1024;

    /** Добавить позу из библиотеки в конец программы. */
    public function addAsana(AsanaProgram $program, Asana $asana): AsanaProgramItem
    {
        return AsanaProgramItem::create([
            'program_id' => $program->id,
            'asana_id' => $asana->id,
            'position' => $this->nextPosition($program),
        ]);
    }

    /** Сдвинуть позу на шаг вверх или вниз, меняя её местами с соседом. */
    public function move(AsanaProgramItem $item, int $direction): void
    {
        $neighbour = AsanaProgramItem::query()
            ->where('program_id', $item->program_id)
            ->when(
                $direction < 0,
                fn ($q) => $q->where('position', '<', $item->position)->orderByDesc('position'),
                fn ($q) => $q->where('position', '>', $item->position)->orderBy('position'),
            )
            ->first();

        if ($neighbour === null) {
            return;
        }

        DB::transaction(function () use ($item, $neighbour) {
            $itemPosition = $item->position;

            $item->update(['position' => $neighbour->position]);
            $neighbour->update(['position' => $itemPosition]);
        });
    }

    /**
     * Разложить позы в порядке, пришедшем после перетаскивания.
     *
     * @param  list<int>  $orderedIds
     */
    public function reorder(AsanaProgram $program, array $orderedIds): void
    {
        $items = $program->items()->get()->keyBy('id');

        DB::transaction(function () use ($items, $orderedIds) {
            $position = 0;

            foreach ($orderedIds as $id) {
                $item = $items->get((int) $id);

                if ($item === null) {
                    continue;
                }

                $item->update(['position' => $position]);
                $position++;
                $items->forget($item->id);
            }

            // Всё, что не пришло в списке, оставляем в хвосте.
            foreach ($items as $leftover) {
                $leftover->update(['position' => $position]);
                $position++;
            }
        });
    }

    public function remove(AsanaProgramItem $item): void
    {
        $this->deleteCustomImage($item->image_path);
        $item->delete();
    }

    /**
     * Сохранить зарисовку как новую позу в личной библиотеке.
     *
     * Категорию можно указать — тогда своя поза встанет в общий раздел рядом
     * с готовыми («Асаны стоя» и прочие), а не только в «Мои зарисовки».
     */
    public function storeCustomAsana(string $dataUrl, ?string $name = null, ?string $category = null): Asana
    {
        $path = $this->storeImage($dataUrl);

        return Asana::create([
            'name' => trim((string) $name) ?: 'Своя поза',
            'category' => $this->normalizeCategory($category),
            'image_path' => $path,
            'is_custom' => true,
        ]);
    }

    /** Переложить свою зарисовку в другой раздел библиотеки. */
    public function setCustomAsanaCategory(Asana $asana, ?string $category): Asana
    {
        if (! $asana->is_custom) {
            throw new InvalidArgumentException('Раздел можно менять только у своих зарисовок.');
        }

        $asana->update(['category' => $this->normalizeCategory($category)]);

        return $asana->refresh();
    }

    /**
     * Разделы библиотеки в заданном порядке.
     *
     * @return list<string>
     */
    public function libraryCategories(): array
    {
        return AsanaCategory::query()->ordered()->pluck('name')->all();
    }

    /**
     * Завести свой раздел. Возвращает null, если такой уже есть или имя пустое.
     */
    public function createCategory(string $name): ?AsanaCategory
    {
        $name = $this->cleanName($name);

        if ($name === null || AsanaCategory::query()->where('name', $name)->exists()) {
            return null;
        }

        return AsanaCategory::create([
            'name' => $name,
            'sort' => (int) AsanaCategory::query()->max('sort') + 1,
        ]);
    }

    /**
     * Переименовать раздел. Название хранится и у самих поз, поэтому меняем
     * его сразу в обоих местах — иначе позы потеряют свой раздел.
     */
    public function renameCategory(AsanaCategory $category, string $name): bool
    {
        $name = $this->cleanName($name);

        if ($name === null || $name === $category->name) {
            return false;
        }

        $taken = AsanaCategory::query()
            ->where('name', $name)
            ->whereKeyNot($category->getKey())
            ->exists();

        if ($taken) {
            return false;
        }

        DB::transaction(function () use ($category, $name) {
            Asana::query()->where('category', $category->name)->update(['category' => $name]);
            $category->update(['name' => $name]);
        });

        return true;
    }

    /**
     * Удалить раздел. Позы остаются, но лишаются раздела: удалять вместе
     * с разделом чужие библиотечные позы было бы слишком.
     *
     * @return int Сколько поз осталось без раздела
     */
    public function deleteCategory(AsanaCategory $category): int
    {
        return DB::transaction(function () use ($category) {
            $affected = Asana::query()
                ->where('category', $category->name)
                ->update(['category' => null]);

            $category->delete();

            return $affected;
        });
    }

    private function cleanName(string $name): ?string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');

        return $name === '' ? null : mb_substr($name, 0, 120);
    }

    /** Пускаем только существующий раздел, иначе — без раздела. */
    private function normalizeCategory(?string $category): ?string
    {
        $category = trim((string) $category);

        if ($category === '' || ! in_array($category, $this->libraryCategories(), true)) {
            return null;
        }

        return $category;
    }

    /**
     * Сохранить правку позы внутри программы: базовая асана в библиотеке
     * не меняется, картинка кладётся на сам элемент программы.
     */
    public function storeItemDrawing(AsanaProgramItem $item, string $dataUrl): AsanaProgramItem
    {
        $previous = $item->image_path;

        $item->update(['image_path' => $this->storeImage($dataUrl)]);
        $this->deleteCustomImage($previous);

        return $item->refresh();
    }

    /** Вернуть элемент к исходной позе из библиотеки. */
    public function resetItemDrawing(AsanaProgramItem $item): AsanaProgramItem
    {
        if ($item->asana_id === null) {
            return $item;
        }

        $previous = $item->image_path;

        $item->update(['image_path' => null]);
        $this->deleteCustomImage($previous);

        return $item->refresh();
    }

    /** Копия программы — удобно делать вариацию под конкретного человека. */
    public function duplicate(AsanaProgram $program): AsanaProgram
    {
        return DB::transaction(function () use ($program) {
            $copy = AsanaProgram::create([
                'folder_id' => $program->folder_id,
                'title' => Str::limit($program->title.' (копия)', 250, ''),
                'note' => $program->note,
                // Значение по умолчанию проставляет БД, в свежей модели оно ещё null.
                'sort' => (int) $program->sort,
            ]);

            foreach ($program->items()->get() as $item) {
                AsanaProgramItem::create([
                    'program_id' => $copy->id,
                    'asana_id' => $item->asana_id,
                    // Правку стилусом не переносим — копия начинает с чистой позы
                    // из библиотеки. А вот элемент без позы держится только на своей
                    // картинке, поэтому файл дублируем: общий файл на две программы
                    // означал бы, что удаление одной ломает другую.
                    'image_path' => $item->asana_id === null
                        ? $this->copyCustomImage($item->image_path)
                        : null,
                    'note' => $item->note,
                    'position' => $item->position,
                ]);
            }

            return $copy;
        });
    }

    /**
     * Удалить свою зарисовку из библиотеки. Позу, которая уже стоит в программах,
     * не трогаем — иначе программа молча лишится шага.
     *
     * @return int Количество программ, мешающих удалению
     */
    public function deleteCustomAsana(Asana $asana): int
    {
        if (! $asana->is_custom) {
            throw new InvalidArgumentException('Удалять можно только свои зарисовки.');
        }

        $usedIn = AsanaProgramItem::query()
            ->where('asana_id', $asana->id)
            ->distinct()
            ->count('program_id');

        if ($usedIn > 0) {
            return $usedIn;
        }

        $path = $asana->image_path;
        $asana->delete();
        $this->deleteCustomImage($path);

        return 0;
    }

    private function copyCustomImage(?string $path): ?string
    {
        if (blank($path) || ! str_starts_with($path, self::CUSTOM_DIR.'/')) {
            return $path;
        }

        $source = public_path($path);

        if (! File::exists($source)) {
            return null;
        }

        $relative = self::CUSTOM_DIR.'/'.Str::uuid()->toString().'.png';
        File::copy($source, public_path($relative));

        return $relative;
    }

    private function nextPosition(AsanaProgram $program): int
    {
        return (int) $program->items()->max('position') + 1;
    }

    /** Раскодировать data-URL из холста и положить PNG в public/. */
    private function storeImage(string $dataUrl): string
    {
        if (! preg_match('#^data:image/png;base64,#', $dataUrl)) {
            throw new InvalidArgumentException('Ожидается изображение PNG.');
        }

        $binary = base64_decode(substr($dataUrl, strlen('data:image/png;base64,')), true);

        if ($binary === false || $binary === '') {
            throw new InvalidArgumentException('Не удалось прочитать изображение.');
        }

        if (strlen($binary) > self::MAX_IMAGE_BYTES) {
            throw new InvalidArgumentException('Изображение слишком большое.');
        }

        // Убеждаемся, что это действительно PNG, а не что-то переименованное.
        if (! str_starts_with($binary, "\x89PNG\r\n\x1a\n")) {
            throw new InvalidArgumentException('Файл не похож на PNG.');
        }

        $relative = self::CUSTOM_DIR.'/'.Str::uuid()->toString().'.png';
        $absolute = public_path($relative);

        File::ensureDirectoryExists(dirname($absolute));
        File::put($absolute, $binary);

        return $relative;
    }

    private function deleteCustomImage(?string $path): void
    {
        if (blank($path) || ! str_starts_with($path, self::CUSTOM_DIR.'/')) {
            return;
        }

        $absolute = public_path($path);

        if (File::exists($absolute)) {
            File::delete($absolute);
        }
    }
}
