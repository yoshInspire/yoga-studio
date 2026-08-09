<?php

namespace App\Support;

/**
 * Проверка загружаемой фотографии — одна на сайт, приложение и все виды
 * снимков: аватар, новость, обложка направления, фото тренера на витрине.
 * Правила и тексты ошибок не должны разъезжаться от места к месту.
 *
 * Раньше называлась AvatarValidation; переименована, когда те же правила
 * понадобились картинкам контента (ADMIN_PLAN_2.md, фаза F).
 */
final class PhotoValidation
{
    /** 12 МБ: снимок с телефона в исходном качестве. */
    public const MAX_KILOBYTES = 12288;

    public const FIELD = 'photo';

    /**
     * HEIC с айфонов в списке нет намеренно: GD его не разворачивает, а
     * обработать снимок обязательно — обрезать в квадрат для аватара или
     * ужать до 1920 для контента. Приложение приводит снимок к JPEG само
     * (`toJpeg` в src/lib/photo.ts), на сайте такие файлы почти не встречаются.
     *
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            self::FIELD => ['required', 'file', 'mimes:jpeg,jpg,png,webp', 'max:'.self::MAX_KILOBYTES],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            self::FIELD.'.required' => 'Выберите фотографию.',
            self::FIELD.'.mimes' => 'Такой формат не поддерживается. Подойдут JPEG, PNG или WEBP.',
            self::FIELD.'.max' => 'Фотография слишком большая — до 12 МБ.',
        ];
    }

    /** @return array<string, string> */
    public static function attributes(): array
    {
        return [self::FIELD => 'фотография'];
    }
}
