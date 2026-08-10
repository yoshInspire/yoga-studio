<?php

namespace App\Support;

use App\Models\StudioText;
use Illuminate\Support\Carbon;

/**
 * Текст оферты, извлечённый из загруженного PDF.
 *
 * Хранится строкой JSON в `studio_texts` — там же, где памятка «К вашему
 * визиту». Отдельной таблицы не заводим: это один документ, который целиком
 * переписывается при каждой загрузке файла.
 *
 * Пока текста нет, страница `/oferta` показывает вёрстку, набранную руками
 * 08.08.2026. Это не запасной путь на всякий случай, а честное поведение
 * при неразобранном PDF: лучше прежняя редакция, чем пустая страница.
 */
final class OfferDocument
{
    /**
     * Блоки текста в порядке документа.
     *
     * @return list<array{type: 'heading'|'paragraph', text: string}>
     */
    public static function blocks(): array
    {
        $raw = StudioText::body(StudioText::OFFER_BODY);

        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(
            $decoded,
            static fn ($block) => is_array($block) && isset($block['type'], $block['text']),
        ));
    }

    public static function hasText(): bool
    {
        return self::blocks() !== [];
    }

    /**
     * @param  list<array{type: 'heading'|'paragraph', text: string}>  $blocks
     */
    public static function put(array $blocks): void
    {
        StudioText::put(StudioText::OFFER_BODY, (string) json_encode($blocks, JSON_UNESCAPED_UNICODE));
    }

    public static function forget(): void
    {
        StudioText::query()->where('key', StudioText::OFFER_BODY)->delete();
    }

    /** Когда текст страницы обновлялся в последний раз. */
    public static function updatedAt(): ?Carbon
    {
        $at = StudioText::query()->where('key', StudioText::OFFER_BODY)->value('updated_at');

        return $at === null ? null : Carbon::parse($at);
    }
}
