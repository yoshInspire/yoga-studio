<?php

namespace App\Support;

use App\Models\AsanaProgramItem;
use Illuminate\Support\Collection;

/**
 * Подбор раскладки печати: сколько колонок взять, чтобы занятие уместилось
 * в заданное число листов A4, и какими при этом будут картинки и подписи.
 *
 * Логика простая: чем меньше колонок, тем крупнее позы, поэтому берём
 * наименьшее число колонок, при котором лист ещё не переполняется.
 */
final class AsanaPrintLayout
{
    /** Ширина и высота печатной области A4 при полях 10 мм. */
    private const PAGE_WIDTH_MM = 190.0;

    private const PAGE_HEIGHT_MM = 277.0;

    /** Заголовок занятия и заметка на первом листе. */
    private const HEADER_MM = 20.0;

    /** Панорамный лист не растягиваем выше этого. */
    private const WIDE_MAX_MM = 62.0;

    /**
     * От крупной сетки к плотной. Верхние значения нужны, чтобы на один лист
     * влезала целая практика на сотню поз — как в бумажной тетради.
     */
    private const COLUMN_OPTIONS = [2, 3, 4, 5, 6, 8, 10, 12, 14];

    /**
     * @param  Collection<int, AsanaProgramItem>  $items
     * @param  int  $targetPages  0 — «как поместится», иначе желаемое число листов
     * @return array{columns: int, image_mm: float, cell_mm: float, caption_pt: float, gap_x_mm: float, gap_y_mm: float, pages: int}
     */
    public static function forItems(Collection $items, int $targetPages = 0): array
    {
        $shapes = $items
            ->map(fn (AsanaProgramItem $item): array => [
                'wide' => $item->isWideImage(),
                'ratio' => $item->aspectRatio() ?: 4 / 3,
            ])
            ->values()
            ->all();

        // «Как поместится» — спокойная сетка, позы крупные.
        if ($targetPages < 1) {
            return self::describe($shapes, 3);
        }

        foreach (self::COLUMN_OPTIONS as $columns) {
            $layout = self::describe($shapes, $columns);

            if ($layout['pages'] <= $targetPages) {
                return $layout;
            }
        }

        // Даже самой плотной сеткой не уместилось — отдаём её, лист добавится сам.
        $densest = self::COLUMN_OPTIONS[count(self::COLUMN_OPTIONS) - 1];

        return self::describe($shapes, $densest);
    }

    /**
     * @param  list<array{wide: bool, ratio: float}>  $items
     * @return array{columns: int, image_mm: float, cell_mm: float, caption_pt: float, gap_x_mm: float, gap_y_mm: float, pages: int}
     */
    private static function describe(array $items, int $columns): array
    {
        // Плотная сетка требует и мелких отступов, иначе они съедают лист.
        $gapX = max(1.5, min(5.0, 30.0 / $columns));
        $gapY = max(2.0, min(6.0, 36.0 / $columns));

        $cellWidth = (self::PAGE_WIDTH_MM - $gapX * ($columns - 1)) / $columns;
        $imageHeight = $cellWidth * 3 / 4;

        // Подпись ужимается вместе с ячейкой, но не бесконечно: ниже 4.5 pt
        // читать уже нечего, поэтому там останавливаемся.
        $captionPt = max(4.5, min(9.5, $cellWidth * 0.24));
        $captionHeight = $captionPt * 0.353 * 2.4; // две строки текста в мм

        $normalRow = $imageHeight + $captionHeight + $gapY;

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
                $total += $wideHeight + $captionHeight + $gapY;

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
            'cell_mm' => round($cellWidth, 1),
            'caption_pt' => round($captionPt, 1),
            'gap_x_mm' => round($gapX, 1),
            'gap_y_mm' => round($gapY, 1),
            'pages' => $pages,
        ];
    }
}
