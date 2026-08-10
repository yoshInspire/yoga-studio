<?php

namespace App\Services;

use App\Support\OfferDocument;
use App\Support\OfferStorage;
use App\Support\OfferTextExtractor;
use Illuminate\Http\UploadedFile;

/**
 * Загрузка договора-оферты и синхронизация страницы `/oferta` с файлом.
 *
 * Задача, ради которой сервис существует: **у документа не должно быть двух
 * редакций**. Заказчица правит договор в Word и загружает PDF, а клиенты и
 * магазины приложений читают HTML-страницу — PDF в браузере Android не
 * показывается, а скачивается файлом. До 10.08.2026 текст страницы правили
 * руками после каждой замены файла, и расхождение было вопросом времени.
 *
 * Теперь любой путь загрузки — с телефона и из веб-админки — проходит здесь,
 * и текст страницы пересобирается из самого файла.
 *
 * **Если разобрать PDF не удалось, файл всё равно сохраняется, а прежний
 * текст остаётся на месте.** Молча подменять договор нельзя, но и терять
 * загруженный файл незачем: вызывающий получает `parsed: false` и обязан
 * сказать об этом вслух.
 */
class OfferService
{
    /**
     * Сохранить новый PDF и пересобрать текст страницы.
     *
     * @return array{parsed: bool, blocks: int, message: string}
     */
    public function replace(UploadedFile $pdf): array
    {
        OfferStorage::disk()->putFileAs(
            dirname(OfferStorage::PATH),
            $pdf,
            basename(OfferStorage::PATH),
        );

        return $this->syncFromStoredPdf();
    }

    /**
     * Пересобрать текст страницы из уже лежащего на диске файла.
     *
     * Отдельный метод, потому что веб-админка кладёт файл сама (Filament
     * пишет его прямо на диск), а пересобрать текст всё равно надо.
     *
     * @return array{parsed: bool, blocks: int, message: string}
     */
    public function syncFromStoredPdf(): array
    {
        if (! OfferStorage::exists()) {
            return [
                'parsed' => false,
                'blocks' => 0,
                'message' => 'Файл оферты не найден.',
            ];
        }

        $blocks = OfferTextExtractor::extract(OfferStorage::absolutePath());

        if ($blocks === []) {
            return [
                'parsed' => false,
                'blocks' => count(OfferDocument::blocks()),
                'message' => 'Файл загружен, но текст из него прочитать не удалось — '
                    .'на странице оферты осталась прежняя редакция. Так бывает со сканами и '
                    .'защищёнными PDF. Проверьте страницу и при необходимости пришлите файл, '
                    .'сохранённый из Word.',
            ];
        }

        OfferDocument::put($blocks);

        return [
            'parsed' => true,
            'blocks' => count($blocks),
            'message' => 'Оферта обновлена: страница на сайте пересобрана из файла, '
                .count($blocks).' '.$this->blockWord(count($blocks)).'. Проверьте её глазами — '
                .'это тот текст, который увидят клиенты и магазины приложений.',
        ];
    }

    /** Убрать и файл, и собранный из него текст страницы. */
    public function delete(): void
    {
        OfferStorage::delete();
        // Текст без файла — сирота: проверить его больше не по чему.
        // Страница вернётся к вёрстке, набранной руками.
        OfferDocument::forget();
    }

    private function blockWord(int $count): string
    {
        $mod10 = $count % 10;
        $mod100 = $count % 100;

        if ($mod100 >= 11 && $mod100 <= 14) {
            return 'блоков текста';
        }

        return match ($mod10) {
            1 => 'блок текста',
            2, 3, 4 => 'блока текста',
            default => 'блоков текста',
        };
    }
}
