<?php

namespace App\Support;

/**
 * Правила студии — единственный источник правды.
 *
 * Раньше этот текст лежал прямо в разметке страницы расписания, а сроки отмены
 * дублировали config/studio.php вручную. Теперь блок собирается здесь: его
 * рисует и сайт (partials/studio-rules), и мобильное приложение
 * (GET /api/v1/rules). Меняешь срок в конфиге — меняется в обоих местах.
 */
class StudioRules
{
    /**
     * Пункты правил. `a` — абзацы ответа, допускают <b> и &nbsp;
     * (сайт печатает как есть, API отдаёт очищенный текст).
     *
     * @return list<array{q: string, a: list<string>}>
     */
    public static function items(): array
    {
        $morning = (int) config('studio.cancellation.morning_hours', 14);
        $day = (int) config('studio.cancellation.day_hours', 4);
        $noon = (int) config('studio.cancellation.noon_hour', 12);
        $capacity = (int) config('studio.default_class_capacity', 6);
        $minGroup = (int) config('studio.auto_cancel.min_group_size', 2);

        return [
            [
                'q' => 'Как забронировать место',
                'a' => [
                    'Бронирование доступно в личном кабинете после входа. Выберите день и занятие на&nbsp;ближайшую неделю — система спишет занятие с&nbsp;подходящего абонемента и&nbsp;покажет остаток свободных мест.',
                ],
            ],
            [
                'q' => 'Отмена бронирования',
                'a' => [
                    sprintf(
                        'Отменить бронирование можно заранее, тогда занятие вернётся на абонемент. Для занятий <b>до&nbsp;%d:00</b> — не позднее чем за&nbsp;%d&nbsp;%s до начала, для занятий <b>с&nbsp;%d:00</b> — не позднее чем за&nbsp;%d&nbsp;%s. При более поздней отмене занятие списывается. В те же сроки можно <b>перенести</b> бронирование на другое время: в «Мои бронирования» нажмите «Перенести» и выберите новое занятие в расписании.',
                        $noon,
                        $morning,
                        self::hours($morning),
                        $noon,
                        $day,
                        self::hours($day),
                    ),
                ],
            ],
            [
                'q' => 'Сколько человек в группе',
                'a' => [
                    sprintf(
                        'Группы маленькие — до&nbsp;%d&nbsp;человек, на отдельных занятиях лимит может отличаться. Количество свободных мест видно прямо в расписании.',
                        $capacity,
                    ),
                ],
            ],
            [
                'q' => 'Что если группа не набралась',
                'a' => [
                    sprintf(
                        'Если на групповое занятие забронировано меньше&nbsp;%d&nbsp;мест, оно может быть отменено заранее. Бронирование аннулируется, занятие возвращается на абонемент, а вам приходит уведомление на почту и&nbsp;в&nbsp;Telegram (если они привязаны).',
                        $minGroup,
                    ),
                ],
            ],
            [
                'q' => 'Абонементы и разовые занятия',
                'a' => [
                    'Доступны групповые и индивидуальные абонементы, а также разовые занятия и мероприятия студии (например, йога-нидра) — они оплачиваются отдельно и не входят в обычный абонемент.',
                    'Первое занятие — пробное.',
                ],
            ],
        ];
    }

    /**
     * Вводная строка над правилами. На сайте эта мысль стоит в подзаголовке
     * страницы расписания, в приложении такого подзаголовка нет — отдаём отдельно.
     */
    public static function lead(): string
    {
        return sprintf(
            'Расписание открыто на %d %s вперёд, новая неделя открывается по воскресеньям до 14:00.',
            (int) config('studio.booking_days_ahead', 7),
            self::days((int) config('studio.booking_days_ahead', 7)),
        );
    }

    /**
     * То же самое обычным текстом — для мобильного приложения,
     * которое размечает абзацы само и разметку сайта не понимает.
     *
     * @return list<array{q: string, a: list<string>}>
     */
    public static function plain(): array
    {
        return array_map(fn (array $item) => [
            'q' => $item['q'],
            'a' => array_map(self::stripMarkup(...), $item['a']),
        ], self::items());
    }

    private static function stripMarkup(string $html): string
    {
        // &nbsp; из разметки сайта — обычный пробел (U+00A0 в приложении не нужен:
        // переносы там расставляет сам движок текста).
        $text = str_replace(['&nbsp;', '&mdash;'], [' ', '—'], $html);

        return trim(html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8'));
    }

    private static function hours(int $n): string
    {
        return self::plural($n, 'час', 'часа', 'часов');
    }

    private static function days(int $n): string
    {
        return self::plural($n, 'день', 'дня', 'дней');
    }

    private static function plural(int $n, string $one, string $few, string $many): string
    {
        $mod100 = $n % 100;
        $mod10 = $n % 10;

        if ($mod100 >= 11 && $mod100 <= 14) {
            return $many;
        }

        return match (true) {
            $mod10 === 1 => $one,
            $mod10 >= 2 && $mod10 <= 4 => $few,
            default => $many,
        };
    }
}
