<?php

namespace App\Support;

use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Извлечение текста оферты из PDF.
 *
 * Зачем это вообще есть: заказчица правит договор в Word и загружает **PDF**,
 * а клиенты и магазины приложений видят **HTML-страницу `/oferta`** — PDF в
 * браузере Android не показывается, а скачивается файлом. Раньше текст
 * страницы переписывали руками, и после каждой замены файла версии
 * расходились. Теперь страницу собирает разбор загруженного PDF, то есть
 * расходиться нечему.
 *
 * Разбор вёрстки PDF — дело неточное, поэтому здесь только то, в чём можно
 * быть уверенным: строки, склеенные обратно в абзацы, и заголовки разделов.
 * Ни жирного, ни списков, ни таблиц: угадывать их — значит однажды выдать
 * клиенту документ, в котором пункт выглядит не тем, чем он является.
 * Если разбор не удался, вызывающий обязан оставить прежний текст страницы
 * и честно сказать, что он устарел (`OfferService`).
 */
final class OfferTextExtractor
{
    /**
     * Сокращения, после которых точка не заканчивает предложение.
     *
     * Без этого списка «Фактический адрес: 119 017, г.» обрывало абзац, и
     * «Москва, ул. Островитянова…» уезжало отдельным абзацем.
     *
     * @var list<string>
     */
    private const ABBREVIATIONS = [
        'г', 'ул', 'д', 'к', 'стр', 'п', 'пп', 'ст', 'руб', 'коп', 'т', 'тел',
        'им', 'обл', 'кв', 'корп', 'см', 'др', 'пр', 'рф', 'гг', 'мин',
    ];

    /**
     * Разобрать PDF в блоки текста.
     *
     * @return list<array{type: 'heading'|'paragraph', text: string}>  пустой массив, если разобрать не удалось
     */
    public static function extract(string $absolutePath): array
    {
        try {
            $text = (new Parser)->parseFile($absolutePath)->getText();
        } catch (Throwable) {
            // Зашифрованный PDF, скан без текстового слоя, битый файл — всё
            // это нормальные исходы. Решение, что делать дальше, не наше.
            return [];
        }

        return self::blocksFromText($text);
    }

    /**
     * Разбор уже извлечённого текста — отдельно от чтения PDF, чтобы правила
     * склейки абзацев можно было проверить тестом, а не «прогоном по файлу».
     *
     * @return list<array{type: 'heading'|'paragraph', text: string}>
     */
    public static function blocksFromText(string $text, int $minLines = 10): array
    {
        $lines = self::meaningfulLines($text);

        if (count($lines) < $minLines) {
            // Скан: текстового слоя нет, разбирать нечего.
            return [];
        }

        return self::toBlocks($lines, self::medianLength($lines));
    }

    /**
     * Строки без пустых, без номеров страниц и с одинарными пробелами.
     *
     * @return list<string>
     */
    private static function meaningfulLines(string $text): array
    {
        $lines = [];

        foreach (preg_split("/\R/u", $text) ?: [] as $line) {
            // В PDF попадаются неразрывные и узкие пробелы — для разбора это
            // обычные пробелы, но регулярка `\s` их не ловит.
            $line = trim((string) preg_replace('/[\x{00A0}\x{2007}\x{202F}\s]+/u', ' ', $line));

            if ($line === '' || preg_match('/^\d{1,3}$/', $line)) {
                continue;
            }

            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * @param  list<string>  $lines
     */
    private static function medianLength(array $lines): int
    {
        $lengths = array_map(mb_strlen(...), $lines);
        sort($lengths);

        return $lengths[intdiv(count($lengths), 2)] ?? 80;
    }

    /**
     * @param  list<string>  $lines
     * @return list<array{type: 'heading'|'paragraph', text: string}>
     */
    private static function toBlocks(array $lines, int $median): array
    {
        $blocks = [];
        $current = null;
        $previous = '';
        $previousWasHeading = false;

        foreach ($lines as $i => $line) {
            $heading = self::isHeading($line, $median, $lines[$i + 1] ?? null);

            $startsBlock = $current === null
                || $heading
                || $previousWasHeading
                || self::isClause($line)
                // Строка заметно короче остальных и закончилась точкой —
                // значит абзац кончился, а не перенёсся.
                || (self::endsSentence($previous) && mb_strlen($previous) < $median * 0.8);

            if ($startsBlock) {
                if ($current !== null) {
                    $blocks[] = $current;
                }

                $current = ['type' => $heading ? 'heading' : 'paragraph', 'text' => $line];
            } else {
                $current['text'] .= ' '.$line;
            }

            $previous = $line;
            $previousWasHeading = $heading;
        }

        if ($current !== null) {
            $blocks[] = $current;
        }

        return array_map(static fn (array $block) => [
            'type' => $block['type'],
            'text' => self::tidy($block['text']),
        ], $blocks);
    }

    /**
     * Заголовок раздела: «3. Права и обязанности Сторон», «Приложение № 1».
     *
     * Порог по длине — медиана строки, а не круглое число. Ошибиться в эту
     * сторону дешевле: непризнанный заголовок просто сольётся со своим
     * абзацем, а признанный по ошибке разорвёт предложение пополам —
     * на этом уже спотыкались на «17. В случае нарушения Клиентом… в».
     */
    private static function isHeading(string $line, int $median, ?string $next): bool
    {
        if (mb_strlen($line) >= $median) {
            return false;
        }

        // Запятая или короткое служебное слово в конце — предложение
        // продолжается на следующей строке, заголовки так не кончаются.
        if (preg_match('/(,|\s\p{Ll}{1,3})$/u', $line)) {
            return false;
        }

        // Самый надёжный признак: следующая строка начинается со строчной
        // буквы, то есть это тот же самый оборванный по ширине абзац.
        // Он и отличает «1. Определения и термины» от «1. Студия не несёт
        // ответственности за потенциально возможные или наступившие…».
        if ($next !== null && preg_match('/^\p{Ll}/u', $next)) {
            return false;
        }

        return (bool) preg_match('/^(\d+\.\s+[А-ЯЁA-Z]|ПРИЛОЖЕНИЕ|Приложение\s+№)/u', $line);
    }

    /** Пункт договора: «2.4.», «3.1.1». */
    private static function isClause(string $line): bool
    {
        return (bool) preg_match('/^\d+\.\d+(\.\d+)*\.?\s/u', $line);
    }

    private static function endsSentence(string $line): bool
    {
        if (! preg_match('/[.:;!?»]$/u', $line)) {
            return false;
        }

        if (preg_match('/(?:^|\s)(\p{L}{1,4})\.$/u', $line, $m)) {
            return ! in_array(mb_strtolower($m[1]), self::ABBREVIATIONS, true);
        }

        return true;
    }

    /** Схлопнуть пробелы и вернуть пробел перед тире, приклеенным к слову. */
    private static function tidy(string $text): string
    {
        $text = (string) preg_replace('/\s+/u', ' ', $text);
        $text = (string) preg_replace('/(\p{L})—/u', '$1 —', $text);

        return trim($text);
    }
}
