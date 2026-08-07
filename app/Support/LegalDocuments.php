<?php

namespace App\Support;

/**
 * Реестр правовых документов студии — единственный источник правды о том,
 * какие документы опубликованы и по каким адресам они лежат.
 *
 * Адреса нужны в трёх местах сразу: подвал сайта, экран «Документы»
 * в мобильном приложении (`GET /api/v1/legal`) и карточки в App Store и
 * Google Play, где ссылка на политику обрабатывается автоматической проверкой.
 * Поэтому все три документа обязаны открываться **без авторизации**.
 */
class LegalDocuments
{
    /**
     * @return list<array{slug: string, title: string, short: string, url: string, revision: ?string}>
     */
    public static function items(): array
    {
        return [
            [
                'slug' => 'offer',
                'title' => 'Договор-оферта',
                'short' => 'Условия оказания услуг студией, правила абонементов и посещения.',
                'url' => route('legal.offer'),
                'revision' => self::revision('offer_revision'),
            ],
            [
                'slug' => 'privacy',
                'title' => 'Политика обработки персональных данных',
                'short' => 'Какие данные мы собираем, зачем, сколько храним и как их удалить.',
                'url' => route('legal.privacy'),
                'revision' => self::revision('privacy_revision'),
            ],
            [
                'slug' => 'account-delete',
                'title' => 'Удаление аккаунта',
                'short' => 'Как удалить учётную запись и связанные с ней данные.',
                'url' => route('legal.account-delete'),
                'revision' => null,
            ],
        ];
    }

    /** Дата редакции документа в виде «7 августа 2026», либо null. */
    public static function revision(string $key): ?string
    {
        $raw = config('studio.legal.'.$key);

        if (blank($raw)) {
            return null;
        }

        return RussianDate::dayMonthYear(\Illuminate\Support\Carbon::parse($raw));
    }
}
