<?php

namespace App\Support;

use App\Models\AsanaProgramItem;
use Illuminate\Support\Collection;

/**
 * Подбор раскладки печати: сколько колонок взять, чтобы занятие уместилось
 * в заданное число листов A4, и какой высоты при этом будут картинки.
 *
 * Логика простая: чем меньше колонок, тем крупнее позы, поэтому берём
 * наименьшее число колонок, при котором лист ещё не переполняется.
 */
final class AsanaPrintLayout
{
    /** Ширина и высота печатной области A4 при полях 12 мм. */
    private const PAGE_WIDTH_MM = 186.0;

    private const PAGE_HEIGHT_MM = 273.0;

    /** Заголовок занятия и заметка на первом листе. */
    private const HEADER_MM = 22.0;

    private const GAP_X_MM = 5.0;

    private const GAP_Y_MM = 6.0;

    /** Подпись под картинкой: номер, название и заметка. */
    private const CAPTION_MM = 11.0;

    /** Панорамный лист не растягиваем выше этого. */
    private const WIDE_MAX_MM = 62.0;

    /** От узкой сетки к широкой: 2 колонки — самые крупные позы. */
    private const COLUMN_OPTIONS = [2, 3, 4, 5, 6];

    /**
     * @param  Collection<int, AsanaProgramItem>  $items
     * @param  int  $targetPages  0 — «как поместится», иначе желаемое число листов
     * @return array{columns: int, image_mm: float, pages: int}
     */
    public static function forItems(Collection $items, int $targetPages = 0): array
    {
        $ratios = $items
            ->map(fn (AsanaProgramItem $item): array => [
                'wide' => $item->isWideImage(),
                'ratio' => $item->aspectRatio() ?: 4 / 3,
            ])
            ->values()
            ->all();

        // «Как поместится» — та же раскладка, что была раньше.
        if ($targetPages < 1) {
            return self::describe($ratios, 3);
        }

        foreach (self::COLUMN_OPTIONS as $columns) {
            $layout = self::describe($ratios, $columns);

            if ($layout['pages'] <= $targetPages) {
                return $layout;
            }
        }

        // Даже самой плотной сеткой не уместилось — отдаём её, лист добавится сам.
        $densest = self::COLUMN_OPTIONS[count(self::COLUMN_OPTIONS) - 1];

        return self::describe($ratios, $densest);
    }

    /**
     * @param  list<array{wide: bool, ratio: float}>  $items
     * @return array{columns: int, image_mm: float, pages: int}
     */
    private static function describe(array $items, int $columns): array
    {
        $cellWidth = (self::PAGE_WIDTH_MM - self::GAP_X_MM * ($columns - 1)) / $columns;

        // Обычная поза — 4:3, поэтому высота картинки считается от ширины ячейки.
        $imageHeight = $cellWidth * 3 / 4;
        $normalRow = $imageHeight + self::CAPTION_MM + self::GAP_Y_MM;

        $total = 0.0;
        $inRow = 0;

        foreach ($items as $item) {
            if ($item['wide']) {
                // Панорама занимает строку целиком, поэтому текущую закрываем.
                if ($inRow > 0) {
                    $total += $normalRow;
                    $inRow = 0;
                }

                $wideHeight = min(self::PAGE_WIDTH_MM / $item['ratio'], self::WIDE_MAX_MM);
                $total += $wideHeight + self::CAPTION_MM + self::GAP_Y_MM;

                continue;
            }

            $inRow++;

            if ($inRow === $columns) {
                $total += $normalRow;
                $inRow = 0;
            }
        }

        if ($inRow > 0) {
            $total += $normalRow;
        }

        $available = self::PAGE_HEIGHT_MM - self::HEADER_MM;
        $pages = $total <= 0.0 ? 1 : (int) max(1, ceil($total / $available));

        return [
            'columns' => $columns,
            'image_mm' => round($imageHeight, 1),
            'pages' => $pages,
        ];
    }
}
